<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Station;
use App\Observers\ContactObserver;
use App\Scoring\Exceptions\UnknownRuleSet;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DuplicateCheckService
{
    /**
     * Callsigns whose dupe groups are loaded by one query in a bulk settle.
     */
    private const CALLSIGNS_PER_QUERY = 500;

    /**
     * Prices memoized by mode and GOTA flag during one bulk settle; null
     * outside a bulk settle so live logging always prices fresh.
     *
     * @var array<string, int>|null
     */
    private ?array $bulkPrices = null;

    /**
     * Check if a contact would be a duplicate.
     *
     * A contact is duplicate if the same callsign has already been worked
     * on the same band and mode within the same event configuration.
     * Pass $excludeContactId when re-checking a contact that already exists
     * so it is not reported as a duplicate of itself.
     *
     * This is a read-only preview (used for the live "worked before" hint);
     * anything that writes a contact goes through save()/reevaluate().
     *
     * @return array{is_duplicate: bool, duplicate_of_contact_id: ?int}
     */
    public function check(string $callsign, int $bandId, int $modeId, int $eventConfigurationId, bool $isGotaContact = false, ?int $excludeContactId = null): array
    {
        $existing = Contact::query()
            ->where('event_configuration_id', $eventConfigurationId)
            ->where('callsign', strtoupper(trim($callsign)))
            ->where('band_id', $bandId)
            ->where('mode_id', $modeId)
            ->where('is_gota_contact', $isGotaContact)
            ->where('is_duplicate', false)
            ->when($excludeContactId !== null, fn ($query) => $query->whereKeyNot($excludeContactId))
            ->first();

        return [
            'is_duplicate' => $existing !== null,
            'duplicate_of_contact_id' => $existing?->id,
        ];
    }

    /**
     * Run $callback in a transaction holding a row lock on the event
     * configuration, so concurrent dupe evaluation and inserts for the same
     * event are serialized.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function locked(?int $eventConfigurationId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($eventConfigurationId, $callback) {
            if ($eventConfigurationId !== null) {
                EventConfiguration::query()->whereKey($eventConfigurationId)->lockForUpdate()->first();
            }

            return $callback();
        });
    }

    /**
     * The dupe group a contact belongs to, or null when it cannot be grouped
     * (no callsign, band or mode yet).
     *
     * @return array{event_configuration_id: int, callsign: string, band_id: int, mode_id: int, is_gota_contact: bool}|null
     */
    public function keyFor(Contact $contact): ?array
    {
        if ($contact->event_configuration_id === null || $contact->band_id === null
            || $contact->mode_id === null || blank($contact->callsign)) {
            return null;
        }

        return [
            'event_configuration_id' => (int) $contact->event_configuration_id,
            'callsign' => strtoupper(trim($contact->callsign)),
            'band_id' => (int) $contact->band_id,
            'mode_id' => (int) $contact->mode_id,
            'is_gota_contact' => (bool) $contact->is_gota_contact,
        ];
    }

    /**
     * Save a new or edited contact with its duplicate status and points
     * settled against the rest of its group.
     *
     * Pass the group key the contact had before an edit ($previousKey) so the
     * group it left is re-evaluated too: a dupe there may now be the original.
     *
     * A contact that cannot be grouped (missing band or mode, e.g. from an
     * external logger that sent neither) is stored as a non-duplicate worth
     * 0 points: it can be neither dupe-checked nor priced until it is
     * completed, and an edit that supplies band and mode reprices it.
     *
     * @param  array{event_configuration_id: int, callsign: string, band_id: int, mode_id: int, is_gota_contact: bool}|null  $previousKey
     */
    public function save(Contact $contact, ?array $previousKey = null): void
    {
        $this->locked($contact->event_configuration_id, function () use ($contact, $previousKey) {
            $key = $this->keyFor($contact);

            if ($key === null) {
                $contact->is_duplicate = false;
                $contact->duplicate_of_contact_id = null;
                $contact->points = 0;
                $contact->save();
            } else {
                $this->settleGroup($key, $contact);
            }

            if ($previousKey !== null && $previousKey !== $key) {
                $this->settleGroup($previousKey);
            }
        });
    }

    /**
     * Re-evaluate the group a contact belongs to after it was deleted or
     * restored, promoting or demoting its siblings as needed.
     */
    public function reevaluate(Contact $contact): void
    {
        $key = $this->keyFor($contact);

        if ($key === null) {
            return;
        }

        $this->locked($key['event_configuration_id'], fn () => $this->settleGroup($key));
    }

    /**
     * Settle many dupe groups after a bulk insert (e.g. an ADIF import),
     * evaluating each distinct group once instead of once per contact.
     *
     * Call it inside locked() for the event the contacts belong to, after
     * they have been saved, so the groups see every new contact at once.
     *
     * With $quietly, contacts are saved without model events (no per-contact
     * bonus reconcile or notifications); the caller reconciles once when the
     * bulk write is done. The rulebook's no-credit zeroing still applies.
     *
     * Members are loaded per batch of callsigns rather than per group, so a
     * large import settles in a handful of queries.
     *
     * @param  iterable<array{event_configuration_id: int, callsign: string, band_id: int, mode_id: int, is_gota_contact: bool}|null>  $keys
     * @return int Number of contacts whose stored status or points changed
     */
    public function settleGroups(iterable $keys, bool $quietly = false): int
    {
        /** @var array<int, array<string, array{event_configuration_id: int, callsign: string, band_id: int, mode_id: int, is_gota_contact: bool}>> $wanted */
        $wanted = [];

        foreach ($keys as $key) {
            if ($key !== null) {
                $wanted[$key['event_configuration_id']][$this->signature($key)] = $key;
            }
        }

        $changed = 0;

        foreach ($wanted as $eventConfigurationId => $groupKeys) {
            $config = EventConfiguration::with('event')->find($eventConfigurationId);
            $callsigns = array_values(array_unique(array_column($groupKeys, 'callsign')));

            foreach (array_chunk($callsigns, self::CALLSIGNS_PER_QUERY) as $callsignChunk) {
                $changed += $this->settleCallsigns($eventConfigurationId, $callsignChunk, $config, $quietly, $groupKeys);
            }
        }

        return $changed;
    }

    /**
     * Re-settle every dupe group of an event (used by a rescore), so dupe
     * flags and points follow the same rules as live logging.
     *
     * Groups are settled in chunks of callsigns, each chunk in its own locked
     * transaction, so live logging can write between chunks instead of
     * waiting for the whole event. That is safe because a group is always
     * settled whole inside one lock, and any live write settles the group it
     * touches itself: a group settled earlier stays correct, and one settled
     * later sees the new contact.
     *
     * @return int Number of contacts whose stored status or points changed
     */
    public function settleEvent(int $eventConfigurationId, int $callsignsPerChunk = 200): int
    {
        $callsigns = Contact::query()
            ->where('event_configuration_id', $eventConfigurationId)
            ->whereNotNull('band_id')
            ->whereNotNull('mode_id')
            ->whereNotNull('callsign')
            ->distinct()
            ->orderBy('callsign')
            ->pluck('callsign')
            ->all();

        $changed = 0;

        foreach (array_chunk($callsigns, max(1, $callsignsPerChunk)) as $callsignChunk) {
            $changed += $this->locked($eventConfigurationId, fn (): int => $this->settleCallsigns(
                $eventConfigurationId,
                $callsignChunk,
                EventConfiguration::with('event')->find($eventConfigurationId),
                quietly: true,
            ));
        }

        return $changed;
    }

    /**
     * Settle the dupe groups of the given callsigns from one members query.
     * With $only, just the groups whose signatures it holds are settled.
     *
     * @param  array<int, string>  $callsigns
     * @param  array<string, mixed>|null  $only
     */
    private function settleCallsigns(int $eventConfigurationId, array $callsigns, ?EventConfiguration $config, bool $quietly, ?array $only = null): int
    {
        $members = Contact::query()
            ->where('event_configuration_id', $eventConfigurationId)
            ->whereIn('callsign', $callsigns)
            ->whereNotNull('band_id')
            ->whereNotNull('mode_id')
            ->get();

        $modes = Mode::query()->whereKey($members->pluck('mode_id')->unique()->all())->get()->keyBy('id');

        /** @var array<string, array{key: array{event_configuration_id: int, callsign: string, band_id: int, mode_id: int, is_gota_contact: bool}, members: array<int, Contact>}> $groups */
        $groups = [];

        foreach ($members as $member) {
            $key = $this->keyFor($member);

            if ($key === null) {
                continue;
            }

            $signature = $this->signature($key);

            if ($only !== null && ! isset($only[$signature])) {
                continue;
            }

            if ($config !== null) {
                $member->setRelation('eventConfiguration', $config);
            }

            $groups[$signature]['key'] = $key;
            $groups[$signature]['members'][] = $member;
        }

        $changed = 0;
        $this->bulkPrices = [];

        try {
            foreach ($groups as $group) {
                $changed += $this->settleGroup(
                    $group['key'],
                    quietly: $quietly,
                    members: collect($group['members']),
                    config: $config,
                    mode: $modes->get($group['key']['mode_id']),
                );
            }
        } finally {
            $this->bulkPrices = null;
        }

        return $changed;
    }

    /**
     * @param  array{event_configuration_id: int, callsign: string, band_id: int, mode_id: int, is_gota_contact: bool}  $key
     */
    private function signature(array $key): string
    {
        return implode('|', [$key['event_configuration_id'], $key['callsign'], $key['band_id'], $key['mode_id'], (int) $key['is_gota_contact']]);
    }

    /**
     * Settle one dupe group: the earliest in-period live contact (by qso_time,
     * then id) is the original and earns full points; every later one is a
     * duplicate of it worth 0. Contacts outside the event period earn nothing
     * and never take the original's place, so a mistimed entry cannot turn a
     * genuine contact into a dupe.
     *
     * This is the single place contacts are priced and dupe-flagged: live
     * logging, imports and rescores all settle through it. Bulk callers pass
     * the group's members, configuration and mode they already loaded.
     *
     * @param  array{event_configuration_id: int, callsign: string, band_id: int, mode_id: int, is_gota_contact: bool}  $key
     * @param  Collection<int, Contact>|null  $members
     * @return int Number of contacts whose stored status or points changed
     */
    private function settleGroup(
        array $key,
        ?Contact $pending = null,
        bool $quietly = false,
        ?Collection $members = null,
        ?EventConfiguration $config = null,
        ?Mode $mode = null,
    ): int {
        $members ??= Contact::query()
            ->where('event_configuration_id', $key['event_configuration_id'])
            ->where('callsign', $key['callsign'])
            ->where('band_id', $key['band_id'])
            ->where('mode_id', $key['mode_id'])
            ->where('is_gota_contact', $key['is_gota_contact'])
            ->when($pending?->exists, fn ($query) => $query->whereKeyNot($pending->getKey()))
            ->get();

        if ($pending !== null) {
            $members->push($pending);
        }

        $config ??= EventConfiguration::with('event')->find($key['event_configuration_id']);
        $mode ??= Mode::find($key['mode_id']);
        $original = null;
        $duplicates = [];
        $outOfPeriod = [];
        $changed = 0;

        foreach ($this->chronological($members) as $member) {
            if (! $this->isWithinEventPeriod($member, $config)) {
                $outOfPeriod[] = $member;
            } elseif ($original === null) {
                $original = $member;
            } else {
                $duplicates[] = $member;
            }
        }

        // The original is saved first so a new original has an id for its dupes to point at.
        if ($original !== null) {
            $original->is_duplicate = false;
            $original->duplicate_of_contact_id = null;
            $original->points = $this->pointsFor($original, $config, $mode);
            $changed += (int) $this->saveIfChanged($original, $quietly);
        }

        foreach ($duplicates as $duplicate) {
            $duplicate->is_duplicate = true;
            $duplicate->duplicate_of_contact_id = $original->id;
            $duplicate->points = 0;
            $changed += (int) $this->saveIfChanged($duplicate, $quietly);
        }

        foreach ($outOfPeriod as $contact) {
            $contact->is_duplicate = false;
            $contact->duplicate_of_contact_id = null;
            $contact->points = 0;
            $changed += (int) $this->saveIfChanged($contact, $quietly);
        }

        return $changed;
    }

    /**
     * Save a settled contact if anything changed. A quiet save skips model
     * events, so the observer's no-credit zeroing is applied here first.
     */
    private function saveIfChanged(Contact $contact, bool $quietly = false): bool
    {
        if ($quietly) {
            app(ContactObserver::class)->saving($contact);
        }

        if ($contact->exists && ! $contact->isDirty()) {
            return false;
        }

        if ($quietly) {
            $contact->saveQuietly();
        } else {
            $contact->save();
        }

        return true;
    }

    /**
     * Order a group by qso_time, then id; an unsaved contact sorts after any
     * saved one logged at the same moment.
     *
     * @param  Collection<int, Contact>  $members
     * @return Collection<int, Contact>
     */
    private function chronological(Collection $members): Collection
    {
        return $members->sort(function (Contact $a, Contact $b): int {
            $byTime = ($a->qso_time?->getTimestamp() ?? 0) <=> ($b->qso_time?->getTimestamp() ?? 0);

            return $byTime !== 0 ? $byTime : ($a->id ?? PHP_INT_MAX) <=> ($b->id ?? PHP_INT_MAX);
        })->values();
    }

    private function isWithinEventPeriod(Contact $contact, ?EventConfiguration $config): bool
    {
        $event = $config?->event;

        if ($event?->start_time === null || $event->end_time === null || $contact->qso_time === null) {
            return true;
        }

        return $contact->qso_time->between($event->start_time, $event->end_time);
    }

    /**
     * Full points for an original contact under the event's rulebook. Only
     * the GOTA flag of the station matters for pricing, so a shim stands in.
     * Contacts the rulebook gives no credit are zeroed by ContactObserver.
     */
    private function pointsFor(Contact $contact, ?EventConfiguration $config, ?Mode $mode): int
    {
        if ($config === null || $mode === null) {
            return (int) $contact->points;
        }

        $priceKey = $mode->id.'|'.(int) $contact->is_gota_contact;

        return $this->bulkPrices[$priceKey] ?? $this->priceContact($contact, $config, $mode, $priceKey);
    }

    /**
     * Price a contact under the rulebook, remembering the price during a bulk run.
     */
    private function priceContact(Contact $contact, EventConfiguration $config, Mode $mode, string $priceKey): int
    {
        try {
            $points = $config->pointsForContact($mode, Station::make(['is_gota' => (bool) $contact->is_gota_contact]));
        } catch (UnknownRuleSet) {
            return (int) $contact->points;
        }

        if ($this->bulkPrices !== null) {
            $this->bulkPrices[$priceKey] = $points;
        }

        return $points;
    }
}
