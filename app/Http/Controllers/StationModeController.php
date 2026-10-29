<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers;

use App\Support\StationMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StationModeController extends Controller
{
    /**
     * End the walk-up operator's session and return the browser to the station-mode landing page.
     *
     * The sign-out form reports which sessions this browser still has
     * contacts queued for (queue_checked, queued_sessions); sessions it
     * doesn't name are removed if they logged nothing.
     */
    public function end(Request $request): RedirectResponse
    {
        if (StationMode::isActive()) {
            StationMode::endSession($request, $this->queuedSessionIds($request));
        }

        return redirect()->route('station-mode.landing');
    }

    /**
     * The session ids the browser still holds unsynced contacts for, or null
     * when it couldn't read its queue.
     *
     * @return list<int>|null
     */
    private function queuedSessionIds(Request $request): ?array
    {
        if (! $request->boolean('queue_checked')) {
            return null;
        }

        $reported = $request->input('queued_sessions', '');

        return collect(explode(',', is_string($reported) ? $reported : ''))
            ->map(fn (string $id): string => trim($id))
            ->filter(fn (string $id): bool => ctype_digit($id))
            ->map(fn (string $id): int => (int) $id)
            ->values()
            ->all();
    }
}
