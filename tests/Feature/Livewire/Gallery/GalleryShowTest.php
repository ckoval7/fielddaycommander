<?php

use App\Livewire\Gallery\GalleryShow;
use App\Models\EventConfiguration;
use App\Models\Image;
use App\Models\User;
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
        ->assertSee('Download Album');
});

test('download button hidden for users without manage-images permission', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();
    Image::factory()->create(['event_configuration_id' => $eventConfig->id]);

    Livewire::actingAs($user)
        ->test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->assertDontSee('Download Album');
});

test('download button hidden when no images exist', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-images');
    $eventConfig = EventConfiguration::factory()->create();

    Livewire::actingAs($user)
        ->test(GalleryShow::class, ['eventConfiguration' => $eventConfig])
        ->assertDontSee('Download Album');
});
