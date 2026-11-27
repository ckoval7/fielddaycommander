<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\DemoEvent;
use App\Models\DemoSession;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Frees a seat for a new visitor when the demo is full, by dropping an idle
 * sandbox from whoever holds more than their fair share.
 *
 * There is no per-address cap, because a club trying the demo together often
 * shares one address. Instead, a full demo is split fairly between the
 * addresses holding sandboxes plus the newcomer: an address over its share
 * gives up its least recently used sandbox, provided nobody has touched it
 * for demo.reclaim_idle_minutes. One address holding every seat therefore
 * cannot lock everyone else out, while people actively using their
 * sandboxes keep them.
 */
class DemoSeatReclaimer
{
    public function __construct(private DemoDatabasePool $pool) {}

    /**
     * Drop one sandbox to make room for the visitor with this hash.
     *
     * @return string|null The session UUID of the sandbox dropped, or null when no seat could fairly be taken.
     */
    public function reclaimFor(string $visitorHash): ?string
    {
        /** @var Collection<string, Collection<int, DemoSession>> $holders */
        $holders = DemoSession::query()
            ->where('expires_at', '>', now())
            ->get()
            ->groupBy('visitor_hash')
            // A base collection, so except() drops addresses rather than model keys.
            ->toBase();

        $fairShare = $this->fairShare($holders, $visitorHash);

        if ($holders->get($visitorHash, collect())->count() >= $fairShare) {
            return null;
        }

        $overShare = $holders
            ->except($visitorHash)
            ->filter(fn (Collection $sessions): bool => $sessions->count() > $fairShare)
            ->sortByDesc(fn (Collection $sessions): int => $sessions->count());

        $idleBefore = now()->subMinutes(max(0, (int) config('demo.reclaim_idle_minutes', 10)));

        foreach ($overShare as $sessions) {
            $candidates = $sessions
                ->filter(fn (DemoSession $session): bool => $session->last_seen_at->lt($idleBefore))
                ->sortBy('last_seen_at');

            foreach ($candidates as $session) {
                if ($this->retireAndDrop($session)) {
                    return $session->session_uuid;
                }
            }
        }

        return null;
    }

    /**
     * Seats each address may keep when the demo is full: the cap split
     * between every address holding a sandbox and the newcomer.
     *
     * @param  Collection<string, Collection<int, DemoSession>>  $holders
     */
    private function fairShare(Collection $holders, string $visitorHash): int
    {
        $addresses = $holders->count() + ($holders->has($visitorHash) ? 0 : 1);

        return max(1, intdiv((int) config('demo.max_sessions', 25), $addresses));
    }

    private function retireAndDrop(DemoSession $session): bool
    {
        // Another newcomer may be reclaiming the same sandbox right now.
        if (! $session->retire()) {
            return false;
        }

        try {
            $this->pool->dropSandbox($session->session_uuid);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        try {
            DemoEvent::create([
                'demo_session_id' => $session->id,
                'type' => 'action',
                'name' => 'session.reclaimed',
            ]);
        } catch (Throwable) {
            // Analytics tracking is best-effort.
        }

        return true;
    }
}
