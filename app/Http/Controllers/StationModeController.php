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
     */
    public function end(Request $request): RedirectResponse
    {
        if (StationMode::isActive()) {
            StationMode::endSession($request);
        }

        return redirect()->route('station-mode.landing');
    }
}
