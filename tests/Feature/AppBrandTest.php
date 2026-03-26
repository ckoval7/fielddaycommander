<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    Setting::set('setup_completed', 'true');
});

test('app brand displays uploaded logo', function () {
    $logo = UploadedFile::fake()->image('logo.png');
    $path = $logo->storeAs('branding', 'test-logo.png', 'public');
    Setting::set('site_logo_path', $path);

    $this->get('/')
        ->assertOk()
        ->assertSee('branding/test-logo.png', escape: false);
});

test('app brand shows icon placeholder when no logo uploaded', function () {
    Setting::set('site_logo_path', '');

    $this->get('/')
        ->assertOk()
        ->assertDontSee('branding/', escape: false);
});
