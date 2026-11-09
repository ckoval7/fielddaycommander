<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\DTOs\RelayContactCursor;
use App\Models\Contact;
use App\Models\Relay;
use App\Services\N3fjp\N3fjpContactMapper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Reads the contacts of a relay's event that changed since its cursor.
 *
 * Delivery is at-least-once. updated_at is stamped before the write commits,
 * so a row can appear behind a cursor that has already passed its timestamp.
 * The cursor therefore never settles closer to the present than the overlap,
 * and rows inside the overlap are sent again until they age out of it. Going
 * by the current clock also lets a cursor recover after the clock is set back.
 */
class RelayContactFeedService
{
    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 500;

    /**
     * Longest a change can stay uncommitted after its timestamp. Writes wait
     * on row locks, up to MySQL's default of 50 seconds, after being stamped.
     */
    public const OVERLAP_SECONDS = 60;

    /** @var list<string> */
    private const COLUMNS = [
        'id', 'uuid', 'operating_session_id', 'band_id', 'mode_id', 'submode', 'frequency_hz', 'qso_time',
        'callsign', 'section_id', 'exchange_class', 'power_watts', 'rst_sent', 'rst_received', 'grid_square',
        'contact_name', 'notes', 'external_id', 'updated_at', 'deleted_at',
    ];

    private const RELAY_ID_PREFIX = RelayContactIngestService::SOURCE.':';

    public function __construct(private N3fjpContactMapper $mapper) {}

    /**
     * @return array{contacts: list<array<string, mixed>>, next_cursor: string, has_more: bool}
     */
    public function changes(Relay $relay, ?RelayContactCursor $cursor, int $limit): array
    {
        $cursor ??= RelayContactCursor::start();
        $settledBefore = now()->subSeconds(self::OVERLAP_SECONDS)->format(RelayContactCursor::TIME_FORMAT);

        $rows = $this->rowsAfter($relay, $cursor, $limit + 1);
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        return [
            'contacts' => $page->map(fn (Contact $contact): array => $this->toEntry($contact))->values()->all(),
            'next_cursor' => $this->nextCursor($cursor, $page, $hasMore, $settledBefore)->encode(),
            'has_more' => $hasMore,
        ];
    }

    /**
     * The index on (event_configuration_id, updated_at) ends in the primary
     * key on InnoDB, so it serves both the range and the sort.
     *
     * @return Collection<int, Contact>
     */
    private function rowsAfter(Relay $relay, RelayContactCursor $cursor, int $count): Collection
    {
        return Contact::withTrashed()
            ->select(self::COLUMNS)
            ->where('event_configuration_id', $relay->event_configuration_id)
            ->where('updated_at', '>=', $cursor->updatedAt)
            ->where(fn (Builder $query) => $query
                ->where('updated_at', '>', $cursor->updatedAt)
                ->orWhere('id', '>', $cursor->id))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($count)
            ->with([
                'band:id,name',
                'mode:id,name',
                'section:id,code',
                'operatingSession:id,station_id,operator_user_id',
                'operatingSession.station' => fn ($query) => $query->withTrashed()->select('id', 'name'),
                'operatingSession.operator' => fn ($query) => $query->withTrashed()->select('id', 'call_sign'),
            ])
            ->get();
    }

    /**
     * A page with more after it always moves forward, so any number of rows
     * can share a second. Once a run of pages passes rows too recent to be
     * settled, the rows behind it may still gain a late commit, so the end of
     * the run goes back to where that first happened, however long it took.
     *
     * @param  Collection<int, Contact>  $page
     */
    private function nextCursor(RelayContactCursor $cursor, Collection $page, bool $hasMore, string $settledBefore): RelayContactCursor
    {
        /** @var Contact|null $last */
        $last = $page->last();
        $position = $last === null
            ? $cursor
            : new RelayContactCursor($last->updated_at->format(RelayContactCursor::TIME_FORMAT), $last->id);

        $rewindTo = $cursor->rewindTo;

        if ($last !== null && $position->updatedAt >= $settledBefore) {
            $rewindTo ??= $settledBefore;
        }

        if ($hasMore) {
            return new RelayContactCursor($position->updatedAt, $position->id, $rewindTo);
        }

        $next = $position->earlierOf(new RelayContactCursor($settledBefore, 0));

        return $rewindTo === null ? $next : $next->earlierOf(new RelayContactCursor($rewindTo, 0));
    }

    /**
     * @return array<string, mixed>
     */
    private function toEntry(Contact $contact): array
    {
        $updatedAt = $contact->updated_at->toIso8601ZuluString();
        $idempotencyKey = $this->idempotencyKey($contact);

        if ($contact->trashed()) {
            return array_filter(
                ['uuid' => $contact->uuid, 'deleted' => true, 'updated_at' => $updatedAt, 'idempotency_key' => $idempotencyKey],
                fn (mixed $value): bool => $value !== null,
            );
        }

        $session = $contact->operatingSession;

        return [
            'uuid' => $contact->uuid,
            'deleted' => false,
            'updated_at' => $updatedAt,
            'idempotency_key' => $idempotencyKey,
            'callsign' => $contact->callsign,
            'qso_time' => $contact->qso_time?->toIso8601ZuluString(),
            'band' => $contact->band?->name,
            'mode' => $this->modeName($contact),
            'frequency_hz' => $contact->frequency_hz,
            'operator' => $session?->operator?->call_sign,
            'station' => $session?->station?->name,
            'exchange_class' => $contact->exchange_class,
            'section' => $contact->section?->code,
            'power_watts' => $contact->power_watts,
            'rst_sent' => $contact->rst_sent,
            'rst_received' => $contact->rst_received,
            'grid_square' => $contact->grid_square,
            'contact_name' => $contact->contact_name,
            'notes' => $contact->notes,
        ];
    }

    private function idempotencyKey(Contact $contact): ?string
    {
        return $contact->external_id !== null && str_starts_with($contact->external_id, self::RELAY_ID_PREFIX)
            ? Str::after($contact->external_id, self::RELAY_ID_PREFIX)
            : null;
    }

    /**
     * The name a relay can send back: the logged mode, or PH, CW or DIG for a
     * contact logged by group. The stored name "Phone" would upload as Digital.
     */
    private function modeName(Contact $contact): ?string
    {
        if (filled($contact->submode)) {
            return $contact->submode;
        }

        $name = $contact->mode?->name;

        return $this->mapper->modeGroup($name) ?: $name;
    }
}
