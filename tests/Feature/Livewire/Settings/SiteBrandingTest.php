<?php

use App\Livewire\Settings\SiteBranding;
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
        ->set('primary_color', '#ff0000')
        ->call('save')
        ->assertDispatched('notify');

    expect(Setting::get('site_name'))->toBe('My Club');
    expect(Setting::get('primary_color'))->toBe('#ff0000');
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
