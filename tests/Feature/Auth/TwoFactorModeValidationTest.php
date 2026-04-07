<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );
});

it('boots successfully with valid 2fa mode', function () {
    config(['auth-security.2fa_mode' => 'optional']);

    // If we can make a request without exception, boot succeeded
    $this->get('/login')->assertOk();
});

it('throws exception for invalid 2fa mode', function () {
    config(['auth-security.2fa_mode' => 'bogus']);

    // Re-boot the provider to trigger validation
    app(\App\Providers\FortifyServiceProvider::class, ['app' => app()])->boot();
})->throws(\RuntimeException::class, "Invalid 2FA_MODE: 'bogus'");
