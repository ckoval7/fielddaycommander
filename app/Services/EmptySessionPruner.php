<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\Contact;
use App\Models\OperatingSession;
use Carbon\CarbonInterface;

/**
 * Removes sessions that ended without logging anything, such as the one in
 * between when an operator changes band and then mode on Log Contact.
 *
 * A session with any contact, deleted ones included, is kept so the audit
 * trail of deleted contacts stays intact. Sessions that continued from a
 * removed one are re-pointed to its predecessor, so a band/mode switch
 * chain stays linked across the gap.
 *
 * Call deleteIfEmpty() under the event lock (DuplicateCheckService::locked()
 * or the same EventConfiguration row lock), or use deleteIfEmptyLocked(): the
 * contact sync saves under that lock too, so no contact can land on the
 * session between the check and the delete. Callers also pass on only
 * sessions whose browser reported no contacts still queued for them, since
 * those sync later and need the session.
 *
 * Sessions ended where no browser can report its queue (a takeover, a
 * station captain ending them, the event closing) are left to
 * pruneEndedBefore(), which the sessions:prune-empty schedule runs once they
 * have been ended for a while, giving an open logging tab time to sync.
 */
class EmptySessionPruner
{
    public function __construct(private DuplicateCheckService $dupes) {}

    public function deleteIfEmpty(OperatingSession $session): bool
    {
        if ($session->end_time === null || $session->is_transcription || $session->external_source !== null) {
            return false;
        }

        if (Contact::withTrashed()->where('operating_session_id', $session->id)->exists()) {
            return false;
        }

        OperatingSession::query()
            ->where('previous_session_id', $session->id)
            ->update(['previous_session_id' => $session->previous_session_id]);

        $session->delete();

        return true;
    }

    /**
     * Take the session's event lock, re-read it, and remove it if it is
     * still ended and empty.
     */
    public function deleteIfEmptyLocked(OperatingSession $session): bool
    {
        $eventConfigurationId = $session->station()->value('event_configuration_id');

        return $this->dupes->locked($eventConfigurationId, function () use ($session): bool {
            $fresh = OperatingSession::query()->find($session->id);

            return $fresh !== null && $this->deleteIfEmpty($fresh);
        });
    }

    /**
     * Remove every ended session with no contacts whose last change (the
     * moment it was ended, for a session nothing touched since) is older than
     * $cutoff. Returns how many were removed.
     */
    public function pruneEndedBefore(CarbonInterface $cutoff): int
    {
        $removed = 0;

        OperatingSession::query()
            ->whereNotNull('end_time')
            ->where('is_transcription', false)
            ->whereNull('external_source')
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('contacts', fn ($query) => $query->withTrashed())
            ->lazyById()
            ->each(function (OperatingSession $session) use (&$removed): void {
                if ($this->deleteIfEmptyLocked($session)) {
                    $removed++;
                }
            });

        return $removed;
    }
}
