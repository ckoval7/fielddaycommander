<?php

use App\Livewire\Settings\SiteBranding;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('public');

    $this->user = User::factory()->create();
    $role = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    Permission::create(['name' => 'manage-settings']);
    $role->givePermissionTo('manage-settings');
    $this->user->assignRole($role);
    $this->actingAs($this->user);
});

test('component can mount', function () {
    Livewire::test(SiteBranding::class)
        ->assertStatus(200);
});

test('validates site name required', function () {
    Livewire::test(SiteBranding::class)
        ->set('site_name', '')
        ->call('save')
        ->assertHasErrors(['site_name' => 'required']);
});

test('validates logo max size', function () {
    // Create a 3MB fake image (over 2MB limit)
    $file = UploadedFile::fake()->image('logo.png')->size(3000);

    Livewire::test(SiteBranding::class)
        ->set('site_name', 'Test Site')
        ->set('new_logo', $file)
        ->call('save')
        ->assertHasErrors(['new_logo']);
});

test('saves branding settings', function () {
    Livewire::test(SiteBranding::class)
        ->set('site_name', 'My Club')
        ->set('site_tagline', 'Field Day 2026')
        ->call('save')
        ->assertDispatched('notify');

    expect(Setting::get('site_name'))->toBe('My Club');
    expect(Setting::get('site_tagline'))->toBe('Field Day 2026');
});

test('uploads and saves logo', function () {
    $logo = UploadedFile::fake()->image('logo.png');

    Livewire::test(SiteBranding::class)
        ->set('site_name', 'Test')
        ->set('new_logo', $logo)
        ->call('save');

    $logoPath = Setting::get('site_logo_path');
    expect($logoPath)->not->toBeNull();
    Storage::disk('public')->assertExists($logoPath);
});

test('saves and clears footer text', function () {
    Livewire::test(SiteBranding::class)
        ->set('site_name', 'Test Site')
        ->set('footer_text', 'Copyright 2026 My Club')
        ->call('save')
        ->assertDispatched('notify');

    expect(Setting::get('site_footer_text'))->toBe('Copyright 2026 My Club');

    Livewire::test(SiteBranding::class)
        ->set('site_name', 'Test Site')
        ->set('footer_text', '')
        ->call('save');

    expect(Setting::get('site_footer_text'))->toBe('');
});

test('footer renders custom text when set', function () {
    Setting::set('setup_completed', 'true');
    Setting::set('site_footer_text', 'W1AW Field Day 2026');

    $this->get('/')
        ->assertOk()
        ->assertSee('W1AW Field Day 2026');
});

test('footer hides custom text when empty', function () {
    Setting::set('setup_completed', 'true');
    Setting::set('site_footer_text', '');

    $this->get('/')
        ->assertOk()
        ->assertDontSee('W1AW Field Day 2026');
});

test('footer always shows project info', function () {
    Setting::set('setup_completed', 'true');

    $this->get('/')
        ->assertOk()
        ->assertSee('Field Day Commander v'.config('app.version'))
        ->assertSee('Powered by Laravel');
});

test('removes logo', function () {
    $logo = UploadedFile::fake()->image('logo.png');
    $path = $logo->storeAs('branding', 'test-logo.png', 'public');
    Setting::set('site_logo_path', $path);

    Livewire::test(SiteBranding::class)
        ->call('removeLogo')
        ->assertDispatched('notify');

    Storage::disk('public')->assertMissing($path);
    expect(Setting::get('site_logo_path'))->toBe('');
});

test('saving branding logs to audit log', function () {
    Livewire::test(SiteBranding::class)
        ->set('site_name', 'My Radio Club')
        ->set('site_tagline', 'Field Day 2026')
        ->call('save');

    $auditLog = AuditLog::where('action', 'settings.branding.updated')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->new_values['site_name'])->toBe('My Radio Club');
});

test('can save a welcome message', function () {
    Livewire::test(SiteBranding::class)
        ->set('site_name', 'Test Site')
        ->set('welcome_message', 'Welcome to our Field Day site!')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('site_welcome_message'))->toBe('Welcome to our Field Day site!');
});

test('validates welcome message max length', function () {
    Livewire::test(SiteBranding::class)
        ->set('site_name', 'Test Site')
        ->set('welcome_message', str_repeat('a', 2001))
        ->call('save')
        ->assertHasErrors(['welcome_message']);
});

test('removing logo logs to audit log', function () {
    $logo = UploadedFile::fake()->image('logo.png');
    $path = $logo->storeAs('branding', 'test-logo.png', 'public');
    Setting::set('site_logo_path', $path);

    Livewire::test(SiteBranding::class)
        ->call('removeLogo');

    $auditLog = AuditLog::where('action', 'settings.branding.updated')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->old_values['logo'])->toBe($path);
});
