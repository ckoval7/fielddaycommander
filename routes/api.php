<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Http\Controllers\Api\Agent\V1\ContactController;
use App\Http\Controllers\Api\Agent\V1\EnrollmentController;
use App\Http\Controllers\Api\Agent\V1\HandshakeController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Support\Facades\Route;

/*
 * The contract is resources/api/agent-v1.openapi.yaml. Agents already in the
 * field depend on v1, so only add to it. A breaking change needs a v2.
 */
Route::prefix('agent/v1')->name('api.agent.v1.')->group(function () {
    Route::post('enroll', EnrollmentController::class)
        ->middleware('throttle:agent-enroll')
        ->name('enroll');

    Route::middleware([AuthenticateAgent::class, 'throttle:agent'])->group(function () {
        Route::get('handshake', HandshakeController::class)->name('handshake');
        Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
    });
});
