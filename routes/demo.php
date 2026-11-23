<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Http\Controllers\DemoAnalyticsController;
use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

Route::get('/demo', [DemoController::class, 'landing'])->name('demo.landing');
Route::post('/demo/provision', [DemoController::class, 'provision'])
    ->name('demo.provision')
    ->middleware('throttle:demo-provision');
Route::post('/demo/reset', [DemoController::class, 'reset'])
    ->name('demo.reset')
    ->middleware('throttle:demo-reset');
Route::get('/demo/join/{token}', [DemoController::class, 'showJoin'])
    ->name('demo.join')
    ->middleware('throttle:30,1');
Route::post('/demo/join/{token}', [DemoController::class, 'join'])
    ->name('demo.join.store')
    ->middleware('throttle:10,1');
Route::post('/demo/heartbeat', [DemoAnalyticsController::class, 'heartbeat'])
    ->name('demo.heartbeat')
    ->middleware('throttle:demo-heartbeat');
Route::post('/demo/analytics/beacon', [DemoAnalyticsController::class, 'beacon'])
    ->name('demo.analytics.beacon')
    ->middleware('throttle:60,1');

Route::middleware('signed')
    ->get('/demo/analytics', [DemoAnalyticsController::class, 'dashboard'])
    ->name('demo.analytics.dashboard');

Route::middleware('signed')
    ->get('/demo/analytics/api', [DemoAnalyticsController::class, 'api'])
    ->name('demo.analytics.api');

Route::middleware('signed')
    ->get('/demo/analytics/sessions/{session}/events', [DemoAnalyticsController::class, 'sessionEvents'])
    ->name('demo.analytics.session-events');
