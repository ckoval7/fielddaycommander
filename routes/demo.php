<?php

use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

Route::get('/demo', [DemoController::class, 'landing'])->name('demo.landing');
Route::post('/demo/provision', [DemoController::class, 'provision'])
    ->name('demo.provision')
    ->middleware('throttle:10,1');
Route::post('/demo/reset', [DemoController::class, 'reset'])->name('demo.reset');
