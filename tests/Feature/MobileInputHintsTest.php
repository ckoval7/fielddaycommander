<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Guestbook\GuestbookForm;
use App\Livewire\Messages\W1awBulletinForm;
use App\Livewire\Profile\UserProfile;
use App\Livewire\Settings\SystemPreferences;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\User;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );
});

test('login pairs the username and current password autocomplete tokens', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('autocomplete="username"', false)
        ->assertSee('autocomplete="current-password"', false);
});

test('register gives the call sign a capitalized keyboard and names their autocomplete tokens', function () {
    config(['auth-security.registration_mode' => 'open']);

    $this->get('/register')
        ->assertOk()
        ->assertSee('autocapitalize="characters"', false)
        ->assertSee('autocorrect="off"', false)
        ->assertSee('spellcheck="false"', false)
        ->assertSee('autocomplete="given-name"', false)
        ->assertSee('autocomplete="family-name"', false)
        ->assertSee('autocomplete="email"', false)
        ->assertSee('autocomplete="new-password"', false);
});

test('the exchange input asks for a send key and an uppercase keyboard', function () {
    view()->share('errors', new ViewErrorBag);

    $html = Blade::render('<x-logging.exchange-composer :suggestions="[]" />');

    expect($html)
        ->toContain('enterkeyhint="send"')
        ->toContain('autocapitalize="characters"')
        ->toContain('autocorrect="off"')
        ->toContain('spellcheck="false"')
        ->toContain('autocomplete="off"')
        ->toContain('@keydown.enter.prevent');
});

test('the W1AW bulletin frequency field uses a decimal keyboard', function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);
    $event = Event::factory()->create(['event_type_id' => EventType::where('code', 'FD')->first()->id]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);
    session(['viewing_event_id' => $event->id]);

    $operator = User::factory()->create();
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $operator->givePermissionTo('log-contacts');

    Livewire::actingAs($operator)
        ->test(W1awBulletinForm::class)
        ->assertSeeHtml('inputmode="decimal"');
});

test('system preferences use a phone keyboard and an uppercase callsign keyboard', function () {
    $admin = User::factory()->create();
    $role = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    Permission::create(['name' => 'manage-settings']);
    $role->givePermissionTo('manage-settings');
    $admin->assignRole($role);

    Livewire::actingAs($admin)
        ->test(SystemPreferences::class)
        ->assertSeeHtml('type="tel"')
        ->assertSeeHtml('autocapitalize="characters"');
});

test('the profile form autofills the user\'s own name and email', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(UserProfile::class)
        ->assertSeeHtml('autocomplete="given-name"')
        ->assertSeeHtml('autocomplete="family-name"')
        ->assertSeeHtml('autocomplete="email"');
});

test('the guestbook form autofills the visitor name and email and capitalizes the callsign', function () {
    $event = Event::factory()->create([
        'is_active' => true,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'guestbook_enabled' => true,
    ]);

    Livewire::test(GuestbookForm::class)
        ->assertSeeHtml('autocomplete="name"')
        ->assertSeeHtml('autocomplete="email"')
        ->assertSeeHtml('autocapitalize="characters"');
});
