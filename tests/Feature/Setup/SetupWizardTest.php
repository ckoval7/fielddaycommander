<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('public');
    Storage::fake('local');
    Setting::where('key', 'setup_completed')->delete();
});

test('setup routes redirect to home when setup is already complete', function () {
    Setting::set('setup_completed', 'true');

    $this->get(route('setup.welcome'))->assertRedirect('/');
    $this->get(route('setup.branding'))->assertRedirect('/');
    $this->get(route('setup.preferences'))->assertRedirect('/');
    $this->post(route('setup.step-1'), [
        'admin_password' => 'StrongPass123!@#',
        'admin_password_confirmation' => 'StrongPass123!@#',
    ])->assertRedirect('/');
    $this->post(route('setup.complete'), [])->assertRedirect('/');
});

test('step 1 displays admin password form', function () {
    $this->get(route('setup.welcome'))
        ->assertOk()
        ->assertSee('Step 1: Admin Password')
        ->assertSee('name="admin_password"', false)
        ->assertSee('name="admin_password_confirmation"', false)
        ->assertSee('action="'.route('setup.step-1').'"', false);
});

test('step 1 validates password requirements', function () {
    $response = $this->post(route('setup.step-1'), [
        'admin_password' => 'weak',
        'admin_password_confirmation' => 'weak',
    ]);

    $response->assertSessionHasErrors('admin_password');
});

test('step 1 validation errors redirect back to step 1', function () {
    $response = $this->post(route('setup.step-1'), [
        'admin_password' => 'nouppercase1!',
        'admin_password_confirmation' => 'nouppercase1!',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('admin_password');
});

test('step 1 rejects password missing symbols', function () {
    $response = $this->post(route('setup.step-1'), [
        'admin_password' => 'NoSymbols12345',
        'admin_password_confirmation' => 'NoSymbols12345',
    ]);

    $response->assertSessionHasErrors('admin_password');
});

test('step 1 requires password confirmation', function () {
    $response = $this->post(route('setup.step-1'), [
        'admin_password' => 'StrongPass123!@#',
        'admin_password_confirmation' => 'DifferentPass123!@#',
    ]);

    $response->assertSessionHasErrors('admin_password');
});

test('step 1 stores password in session and redirects to step 2', function () {
    $response = $this->post(route('setup.step-1'), [
        'admin_password' => 'StrongPass123!@#',
        'admin_password_confirmation' => 'StrongPass123!@#',
    ]);

    $response->assertRedirect(route('setup.branding'));
    $response->assertSessionHas('setup_wizard.step1.admin_password');
});

test('step 2 displays branding form', function () {
    $this->get(route('setup.branding'))
        ->assertOk()
        ->assertSee('Step 2: Site Branding')
        ->assertSee('name="site_name"', false)
        ->assertSee('name="site_tagline"', false)
        ->assertSee('name="logo"', false)
        ->assertSee('action="'.route('setup.step-2').'"', false);
});

test('step 2 validates site name required', function () {
    $response = $this->post(route('setup.step-2'), [
        'site_name' => '',
    ]);

    $response->assertSessionHasErrors('site_name');
});

test('step 2 validates logo file type', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100);

    $response = $this->post(route('setup.step-2'), [
        'site_name' => 'Test Site',
        'logo' => $file,
    ]);

    $response->assertSessionHasErrors('logo');
});

test('step 2 rejects svg logos', function () {
    $file = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

    $response = $this->post(route('setup.step-2'), [
        'site_name' => 'Test Site',
        'logo' => $file,
    ]);

    $response->assertSessionHasErrors('logo');
});

test('step 2 stores data in session and redirects to step 3', function () {
    $response = $this->post(route('setup.step-2'), [
        'site_name' => 'My Club',
        'site_tagline' => 'Field Day 2026',
    ]);

    $response->assertRedirect(route('setup.preferences'));
    $response->assertSessionHas('setup_wizard.step2.site_name', 'My Club');
});

test('step 3 displays preferences form', function () {
    $this->get(route('setup.preferences'))
        ->assertOk()
        ->assertSee('Step 3: System Preferences')
        ->assertSee('name="organization_name"', false)
        ->assertSee('name="timezone"', false)
        ->assertSee('name="date_format"', false)
        ->assertSee('action="'.route('setup.complete').'"', false);
});

test('step 3 validates timezone required', function () {
    $this->session(['setup_wizard.step1' => ['admin_password' => 'pass']]);
    $this->session(['setup_wizard.step2' => ['site_name' => 'Test']]);

    $response = $this->post(route('setup.complete'), [
        'organization_name' => 'Test Org',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i:s',
    ]);

    $response->assertSessionHasErrors('timezone');
});

test('step 3 validates organization name required', function () {
    $this->session(['setup_wizard.step1' => ['admin_password' => 'pass']]);
    $this->session(['setup_wizard.step2' => ['site_name' => 'Test']]);

    $response = $this->post(route('setup.complete'), [
        'timezone' => 'America/New_York',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i:s',
    ]);

    $response->assertSessionHasErrors('organization_name');
});

test('step 3 validates callsign format', function () {
    $this->session(['setup_wizard.step1' => ['admin_password' => 'pass']]);
    $this->session(['setup_wizard.step2' => ['site_name' => 'Test']]);

    $response = $this->post(route('setup.complete'), [
        'organization_name' => 'Test Org',
        'organization_callsign' => 'invalid-callsign',
        'timezone' => 'America/New_York',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i:s',
    ]);

    $response->assertSessionHasErrors('organization_callsign');
});

test('complete wizard saves all settings', function () {
    $admin = User::factory()->create(['call_sign' => 'SYSTEM']);

    $this->session([
        'setup_wizard.step1' => ['admin_password' => 'NewPass123!@#'],
        'setup_wizard.step2' => [
            'site_name' => 'Test Club',
            'site_tagline' => 'FD 2026',
        ],
    ]);

    $response = $this->post(route('setup.complete'), [
        'organization_name' => 'Springfield Amateur Radio Club',
        'organization_callsign' => 'W1ABC',
        'organization_email' => 'info@sparc.org',
        'organization_phone' => '(555) 123-4567',
        'timezone' => 'America/New_York',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i:s',
        'contact_email' => 'contact@example.com',
    ]);

    $response->assertRedirect(route('login'));

    // Verify settings saved
    expect(Setting::get('site_name'))->toBe('Test Club');
    expect(Setting::get('timezone'))->toBe('America/New_York');
    expect(Setting::getBoolean('setup_completed'))->toBeTrue();

    // Verify organization created
    $organization = Organization::first();
    expect($organization)->not->toBeNull();
    expect($organization->name)->toBe('Springfield Amateur Radio Club');
    expect($organization->callsign)->toBe('W1ABC');
    expect($organization->email)->toBe('info@sparc.org');
    expect($organization->phone)->toBe('(555) 123-4567');
    expect($organization->is_active)->toBeTrue();

    // Verify default organization ID saved
    expect((int) Setting::get('default_organization_id'))->toBe($organization->id);

    // Verify admin password updated
    $admin->refresh();
    expect(Hash::check('NewPass123!@#', $admin->password))->toBeTrue();
});

test('complete wizard handles logo upload', function () {
    User::factory()->create(['call_sign' => 'SYSTEM']);
    $logo = UploadedFile::fake()->image('logo.png', 800, 200);

    // Step 1: Set admin password
    $this->session([
        'setup_wizard.step1' => ['admin_password' => 'NewPass123!@#'],
    ]);

    // Step 2: Upload logo
    $this->post(route('setup.step-2'), [
        'site_name' => 'Test',
        'logo' => $logo,
    ]);

    // Step 3: Complete wizard
    $this->post(route('setup.complete'), [
        'organization_name' => 'Test Organization',
        'timezone' => 'America/New_York',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i:s',
    ]);

    // Verify logo saved to public storage
    $logoPath = Setting::get('site_logo_path');
    expect($logoPath)->not->toBeNull();
    Storage::disk('public')->assertExists($logoPath);
});

test('complete wizard creates organization without optional fields', function () {
    $admin = User::factory()->create(['call_sign' => 'SYSTEM']);

    $this->session([
        'setup_wizard.step1' => ['admin_password' => 'NewPass123!@#'],
        'setup_wizard.step2' => ['site_name' => 'Test'],
    ]);

    $response = $this->post(route('setup.complete'), [
        'organization_name' => 'Minimal Org',
        'timezone' => 'America/New_York',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i:s',
    ]);

    $response->assertRedirect(route('login'));

    // Verify organization created with only required fields
    $organization = Organization::first();
    expect($organization)->not->toBeNull();
    expect($organization->name)->toBe('Minimal Org');
    expect($organization->callsign)->toBeNull();
    expect($organization->email)->toBeNull();
    expect($organization->phone)->toBeNull();
    expect($organization->is_active)->toBeTrue();
});

test('step 3 uppercases a lowercase organization callsign', function () {
    User::factory()->create(['call_sign' => 'SYSTEM']);

    $this->session([
        'setup_wizard.step1' => ['admin_password' => 'NewPass123!@#'],
        'setup_wizard.step2' => ['site_name' => 'Test'],
    ]);

    $this->post(route('setup.complete'), [
        'organization_name' => 'Test Org',
        'organization_callsign' => ' w1abc ',
        'timezone' => 'America/New_York',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i:s',
    ])->assertSessionHasNoErrors();

    expect(Organization::first()->callsign)->toBe('W1ABC');
});

test('step 3 keeps entered values after a validation error', function () {
    $this->session(['setup_wizard.step1' => ['admin_password' => 'pass']]);
    $this->session(['setup_wizard.step2' => ['site_name' => 'Test']]);

    $this->from(route('setup.preferences'))->post(route('setup.complete'), [
        'organization_name' => 'Test Org',
        'organization_callsign' => 'W1-ABC',
        'timezone' => 'America/New_York',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i:s',
    ])->assertSessionHasErrors('organization_callsign');

    $this->get(route('setup.preferences'))
        ->assertSee('value="W1-ABC"', false)
        ->assertSee('value="Test Org"', false);
});
