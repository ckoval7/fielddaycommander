<?php

use App\Http\Controllers\ContactSyncController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:web')->group(function () {
    Route::post('/logging/contacts', [ContactSyncController::class, 'store']);
});
