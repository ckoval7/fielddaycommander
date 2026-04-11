<?php

use App\Http\Controllers\DemoAnalyticsController;
use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

Route::get('/demo', [DemoController::class, 'landing'])->name('demo.landing');
Route::post('/demo/provision', [DemoController::class, 'provision'])
    ->name('demo.provision')
    ->middleware('throttle:10,1');
Route::post('/demo/reset', [DemoController::class, 'reset'])
    ->name('demo.reset')
    ->middleware('throttle:5,1');
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
