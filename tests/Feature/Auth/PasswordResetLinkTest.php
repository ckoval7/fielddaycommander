<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\User;
use App\Support\PasswordResetLinks;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );

    // phpunit.xml sets MAIL_MAILER=array, which leaves Fortify's reset routes unregistered.
    Route::middleware('web')->group(function () {
        Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
        Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    });
    app('router')->getRoutes()->refreshNameLookups();

    $this->user = User::factory()->create(['email' => 'victim@example.com']);
});

test('reset links use APP_URL, not the request host', function () {
    config(['app.url' => 'https://fd.example.org']);
    Notification::fake();

    $this->withServerVariables(['HTTP_HOST' => '192.168.1.66'])
        ->post('http://192.168.1.66/forgot-password', ['email' => 'victim@example.com'])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification): bool {
        $url = $notification->toMail($this->user)->actionUrl;

        return str_starts_with($url, 'https://fd.example.org/reset-password/')
            && ! str_contains($url, '192.168.1.66');
    });
});

test('a .local host header cannot redirect the reset link', function () {
    config(['app.url' => 'http://fieldday.lan']);
    Notification::fake();

    $this->post('http://attacker.local/forgot-password', ['email' => 'victim@example.com'])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification): bool {
        return str_starts_with($notification->toMail($this->user)->actionUrl, 'http://fieldday.lan/reset-password/');
    });
});

test('self-service reset is refused while APP_URL is localhost', function (string $appUrl) {
    config(['app.url' => $appUrl]);
    Notification::fake();

    $this->post('http://192.168.1.66/forgot-password', ['email' => 'victim@example.com'])
        ->assertSessionHasErrors(['email' => PasswordResetLinks::UNAVAILABLE_MESSAGE]);

    Notification::assertNothingSent();
})->with(['http://localhost', 'http://127.0.0.1:8000', 'http://[::1]', '']);

test('forgot password page explains how to get a reset while APP_URL is localhost', function () {
    config(['app.url' => 'http://localhost']);

    $this->get('/forgot-password')
        ->assertOk()
        ->assertSee('Ask an administrator to reset your password.', escape: false)
        ->assertDontSee('Email Reset Link');
});

test('forgot password page shows the form once APP_URL is set', function () {
    config(['app.url' => 'https://fd.example.org']);

    $this->get('/forgot-password')
        ->assertOk()
        ->assertSee('Email Reset Link');
});

test('without APP_URL an admin-sent reset link uses the admin\'s own address', function () {
    config(['app.url' => 'http://localhost']);
    $this->get('http://192.168.1.10/forgot-password');

    $url = PasswordResetLinks::url('token123', 'victim@example.com');

    expect($url)->toStartWith('http://192.168.1.10/reset-password/token123');
});
