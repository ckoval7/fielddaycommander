<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Http\Controllers\Api\Relay\V1\ContactController;
use App\Http\Controllers\Api\Relay\V1\EnrollmentController;
use App\Http\Controllers\Api\Relay\V1\HandshakeController;
use App\Http\Middleware\AuthenticateRelay;
use App\Http\Middleware\RequireRelayHttps;
use Illuminate\Support\Facades\Route;

/*
 * The contract is resources/api/relay-v1.openapi.yaml. Relays already in the
 * field depend on v1, so only add to it. A breaking change needs a v2.
 */
Route::prefix('relay/v1')->name('api.relay.v1.')->middleware(RequireRelayHttps::class)->group(function () {
    Route::post('enroll', EnrollmentController::class)
        ->middleware('throttle:relay-enroll')
        ->name('enroll');

    Route::middleware([AuthenticateRelay::class, 'throttle:relay'])->group(function () {
        Route::get('handshake', HandshakeController::class)->name('handshake');
        Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
    });
});
