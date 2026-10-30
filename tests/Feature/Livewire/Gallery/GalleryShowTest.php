<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Gallery\GalleryShow;
use App\Models\EventConfiguration;
use App\Models\Image;
use App\Models\StationDevice;
use App\Models\User;
use App\Support\StationMode;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::create(['name' => 'manage-images']);
});

test('gallery show displays event photos', function () {
    $eventConfig = EventConfiguration::factory()->create();
    $image = Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'caption' => 'Test photo caption',
    ]);

    Livewire::test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->assertStatus(200)
        ->assertSee('Test photo caption');
});

test('gallery show displays uploader name', function () {
    $user = User::factory()->create(['first_name' => 'John', 'last_name' => 'Smith']);
    $eventConfig = EventConfiguration::factory()->create();
    $image = Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'uploaded_by_user_id' => $user->id,
    ]);

    Livewire::test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->assertSee('John Smith');
});

test('gallery show allows authenticated users to upload', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    Livewire::actingAs($user)
        ->test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->assertSee('Upload');
});

test('gallery show hides upload for guests', function () {
    $eventConfig = EventConfiguration::factory()->create();

    Livewire::test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->assertDontSee('Upload');
});

test('user can delete their own photo', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();
    $image = Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'uploaded_by_user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->call('deleteImage', $image->id)
        ->assertDispatched('notify');

    expect(Image::find($image->id))->toBeNull();
});

test('user cannot delete others photos', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();
    $image = Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'uploaded_by_user_id' => $otherUser->id,
    ]);

    Livewire::actingAs($user)
        ->test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->call('deleteImage', $image->id)
        ->assertForbidden();

    expect(Image::find($image->id))->not->toBeNull();
});

test('admin can delete any photo', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-images');

    $otherUser = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();
    $image = Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'uploaded_by_user_id' => $otherUser->id,
    ]);

    Livewire::actingAs($admin)
        ->test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->call('deleteImage', $image->id)
        ->assertDispatched('notify');

    expect(Image::find($image->id))->toBeNull();
});

test('download button visible for users with manage-images permission', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-images');
    $eventConfig = EventConfiguration::factory()->create();
    Image::factory()->create(['event_configuration_id' => $eventConfig->id]);

    Livewire::actingAs($user)
        ->test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->assertSee('Prepare Album ZIP');
});

test('download button hidden for users without manage-images permission', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();
    Image::factory()->create(['event_configuration_id' => $eventConfig->id]);

    Livewire::actingAs($user)
        ->test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->assertDontSee('Prepare Album ZIP');
});

test('download button hidden when no images exist', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-images');
    $eventConfig = EventConfiguration::factory()->create();

    Livewire::actingAs($user)
        ->test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->assertDontSee('Prepare Album ZIP');
});

test('gallery show hides the upload button in station mode', function () {
    $eventConfig = EventConfiguration::factory()->create();
    $device = StationDevice::factory()->create();

    $this->actingAs(User::factory()->create());

    Livewire::test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->assertSee('Upload Photo');

    session([StationMode::SESSION_KEY => $device->id]);

    Livewire::test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->assertDontSee('Upload Photo');
});

test('gallery delete button is visible on touch devices and has an accessible tap target', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();
    Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'uploaded_by_user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->assertSeeHtml('aria-label="Delete photo: ')
        ->assertSeeHtml('pointer-coarse:min-h-11 pointer-coarse:min-w-11')
        ->assertSeeHtml('opacity-100 pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100 group-focus-within:opacity-100')
        ->assertDontSeeHtml('rounded-full opacity-0 group-hover:opacity-100');
});

test('gallery show header actions wrap so they never push past a phone screen', function () {
    $eventConfig = EventConfiguration::factory()->create();

    Livewire::test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->assertSeeHtml('flex-wrap max-sm:w-full');
});

test('lightbox lets people zoom into the photo and open it full size', function () {
    $eventConfig = EventConfiguration::factory()->create();
    $image = Image::factory()->create(['event_configuration_id' => $eventConfig->id]);

    Livewire::test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->call('openLightbox', $image->id)
        ->assertSeeHtml('x-ref="viewport"')
        ->assertSeeHtml(':aria-label="zoomed ? \'Fit photo to screen\' : \'Zoom into photo\'"')
        ->assertSee('Zoom in')
        ->assertSeeHtml('href="'.route('gallery.image', $image).'" target="_blank"')
        ->assertSeeHtml('max-sm:w-full max-sm:max-w-none');
});
