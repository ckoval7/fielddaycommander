<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services\N3fjp;

use App\Models\Contact;
use App\Models\EventConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Keeps an event's log and the N3FJP clients' copies of it in step.
 *
 * N3FJP clients number records themselves: each counts the ADDs the server
 * relays and sets its counter to the last record of a full reload. So the
 * server hands out numbers strictly in order, the next one is always the
 * highest live number plus one, and a deleted contact gives up its number.
 * That keeps a client that just reloaded and a client that saw every ADD
 * agreeing on the number of the next contact.
 *
 * Changes made in Field Day Commander are found by polling contacts updated
 * since the last poll; a hash of each record as last sent to the clients
 * keeps rescoring that changed nothing visible, and the server's own writes,
 * from being sent twice.
 */
class N3fjpLogSync
{
    /**
     * More changed records than this in one poll are sent as a full reload,
     * which N3FJP clients process far faster than a run of EDITs.
     */
    public const RELOAD_THRESHOLD = 50;

    /** @var array<int, string> Hash of the record last sent, by N3FJP key */
    private array $sent = [];

    private int $lastKey = 0;

    private ?Carbon $watermark = null;

    /** Highest contact id seen, so new contacts are found whatever their timestamps say. */
    private int $lastSeenId = 0;

    public function __construct(
        private EventConfiguration $config,
        private N3fjpContactMapper $mapper,
    ) {}

    /**
     * Number every live contact that lacks a number, free the numbers of
     * deleted ones, and remember what the clients will be sent.
     */
    public function boot(): void
    {
        $this->watermark = Carbon::now();
        $this->lastSeenId = (int) Contact::withTrashed()->where('event_configuration_id', $this->config->id)->max('id');

        $this->query()->onlyTrashed()->whereNotNull('n3fjp_key')->toBase()->update(['n3fjp_key' => null]);

        $this->lastKey = (int) $this->query()->max('n3fjp_key');

        $unnumbered = $this->query()->whereNull('n3fjp_key')->orderBy('qso_time')->orderBy('id')->pluck('id');

        foreach ($unnumbered as $contactId) {
            $this->assignKey($contactId);
        }

        $this->sent = [];

        $this->withRelations($this->query()->whereNotNull('n3fjp_key'))
            ->chunkById(500, function (Collection $contacts) {
                foreach ($contacts as $contact) {
                    $this->remember($contact);
                }
            });
    }

    /**
     * Every live contact as GET records, in number order, for a full reload.
     *
     * @return list<string>
     */
    public function fullLog(): array
    {
        $records = [];

        $this->withRelations($this->query()->whereNotNull('n3fjp_key'))
            ->orderBy('n3fjp_key')
            ->each(function (Contact $contact) use (&$records) {
                $records[] = $this->mapper->toRecord($contact, $contact->n3fjp_key);
            }, 500);

        return $records;
    }

    /**
     * What a client compares its log against: how many records there are
     * and the highest number.
     *
     * @return array{int, int}
     */
    public function countAndLastKey(): array
    {
        return [$this->query()->whereNotNull('n3fjp_key')->count(), $this->lastKey];
    }

    public function contactForKey(int $key): ?Contact
    {
        return $this->query()->where('n3fjp_key', $key)->first();
    }

    /**
     * Number a contact a client just added and return its record. The
     * contact must be the newest change, so numbers stay in relay order.
     */
    public function accept(Contact $contact): string
    {
        $this->assignKey($contact->id);
        $this->lastSeenId = max($this->lastSeenId, $contact->id);

        return $this->recordFor($contact->id);
    }

    /**
     * The current record of a numbered contact, remembered as sent.
     */
    public function recordFor(int $contactId): string
    {
        $contact = $this->withRelations($this->query())->findOrFail($contactId);

        return $this->remember($contact);
    }

    /**
     * Free a deleted contact's number.
     *
     * @return bool Whether it was the highest number, which leaves clients
     *              that saw the ADD a number ahead and needing a reload
     */
    public function release(int $key): bool
    {
        Contact::withTrashed()
            ->where('event_configuration_id', $this->config->id)
            ->where('n3fjp_key', $key)
            ->toBase()
            ->update(['n3fjp_key' => null]);

        unset($this->sent[$key]);

        $wasLast = $key === $this->lastKey;

        if ($wasLast) {
            $this->lastKey = (int) $this->query()->max('n3fjp_key');
        }

        return $wasLast;
    }

    /**
     * Changes made outside the N3FJP clients since the last poll.
     */
    public function poll(): N3fjpChanges
    {
        $since = $this->watermark ?? Carbon::now();
        $changes = new N3fjpChanges;

        $changed = $this->withRelations(Contact::withTrashed()->where('event_configuration_id', $this->config->id))
            ->where(fn (Builder $query) => $query
                ->where('updated_at', '>=', $since->copy()->subSecond())
                ->orWhere('id', '>', $this->lastSeenId))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();

        foreach ($changed as $contact) {
            if ($contact->updated_at !== null && $contact->updated_at->greaterThan($since)) {
                $since = $contact->updated_at;
            }

            $this->lastSeenId = max($this->lastSeenId, $contact->id);

            $this->applyChange($contact, $changes);
        }

        $this->watermark = $since;

        if (count($changes->edits) > self::RELOAD_THRESHOLD) {
            $changes->reloadAll = true;
        }

        return $changes;
    }

    private function applyChange(Contact $contact, N3fjpChanges $changes): void
    {
        if ($contact->trashed()) {
            if ($contact->n3fjp_key !== null) {
                $key = $contact->n3fjp_key;
                $changes->deletes[] = $key;
                $changes->checkAfter = $this->release($key) || $changes->checkAfter;
            }

            return;
        }

        if ($contact->n3fjp_key === null) {
            $contact->n3fjp_key = $this->assignKey($contact->id);
            $changes->adds[] = $this->remember($contact);

            return;
        }

        $record = $this->mapper->toRecord($contact, $contact->n3fjp_key);

        if (($this->sent[$contact->n3fjp_key] ?? null) !== self::fingerprint($record)) {
            $changes->edits[] = $this->remember($contact);
        }
    }

    /**
     * Give a contact the next number, without touching its updated_at.
     */
    private function assignKey(int $contactId): int
    {
        $key = ++$this->lastKey;

        Contact::withTrashed()->whereKey($contactId)->toBase()->update(['n3fjp_key' => $key]);

        return $key;
    }

    private function remember(Contact $contact): string
    {
        $record = $this->mapper->toRecord($contact, $contact->n3fjp_key);
        $this->sent[$contact->n3fjp_key] = self::fingerprint($record);

        return $record;
    }

    /**
     * A fast, non-cryptographic hash of a record, to tell whether it changed.
     */
    private static function fingerprint(string $record): string
    {
        return hash('xxh128', $record);
    }

    /**
     * @return Builder<Contact>
     */
    private function query(): Builder
    {
        return Contact::query()->where('event_configuration_id', $this->config->id);
    }

    /**
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    private function withRelations(Builder $query): Builder
    {
        return $query->with(['band', 'mode', 'section', 'operatingSession.station', 'operatingSession.operator']);
    }
}
