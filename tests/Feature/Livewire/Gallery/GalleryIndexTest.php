<?php

use App\Livewire\Gallery\GalleryIndex;
use App\Models\EventConfiguration;
use App\Models\Image;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

test('gallery index is accessible to guests', function () {
    Livewire::test(GalleryIndex::class)
        ->assertStatus(200);
});

test('gallery index shows events with photos', function () {
    $eventConfig = EventConfiguration::factory()->create();
    Image::factory()->count(3)->create(['event_configuration_id' => $eventConfig->id]);

    Livewire::test(GalleryIndex::class)
        ->assertSee($eventConfig->event->name);
});

test('gallery index events list only shows events with photos', function () {
    $eventWithPhotos = EventConfiguration::factory()->create();
    $eventWithPhotos->event->update(['name' => 'Event With Photos']);
    Image::factory()->create(['event_configuration_id' => $eventWithPhotos->id]);

    $eventWithoutPhotos = EventConfiguration::factory()->create();
    $eventWithoutPhotos->event->update(['name' => 'Event Without Photos']);

    $component = Livewire::test(GalleryIndex::class);

    // Event with photos should be in the events list
    $component->assertSee('Event With Photos');

    // The events computed property should only include events with photos
    $events = $component->get('events');
    expect($events)->toHaveCount(1);
    expect($events->first()->event->name)->toBe('Event With Photos');
});

test('gallery index shows photo count per event', function () {
    $eventConfig = EventConfiguration::factory()->create();
    Image::factory()->count(5)->create(['event_configuration_id' => $eventConfig->id]);

    Livewire::test(GalleryIndex::class)
        ->assertSee('5 photos');
});

test('gallery index shows upload button when active event exists', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    // Set active event
    Setting::set('active_event_id', $eventConfig->event_id);

    Livewire::actingAs($user)
        ->test(GalleryIndex::class)
        ->assertSee('Upload Photo');
});

test('gallery index shows upload button when uploadable events exist', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    // No active event set, but uploadable events exist

    Livewire::actingAs($user)
        ->test(GalleryIndex::class)
        ->assertSee('Upload Photo');
});

test('gallery index hides upload button for guests', function () {
    $eventConfig = EventConfiguration::factory()->create();
    Setting::set('active_event_id', $eventConfig->event_id);

    Livewire::test(GalleryIndex::class)
        ->assertDontSee('Upload Photo');
});

test('gallery index shows event selector modal when no active event', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    // No active event set

    Livewire::actingAs($user)
        ->test(GalleryIndex::class)
        ->call('$set', 'showEventSelector', true)
        ->assertSee('Select Event')
        ->assertSee($eventConfig->event->name);
});

test('gallery index redirects to upload when event selected', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    Livewire::actingAs($user)
        ->test(GalleryIndex::class)
        ->set('selectedEventId', $eventConfig->id)
        ->call('uploadToEvent')
        ->assertRedirect(route('gallery.upload', $eventConfig->id));
});

test('download button visible on event cards for users with manage-images permission', function () {
    Permission::create(['name' => 'manage-images']);
    $user = User::factory()->create();
    $user->givePermissionTo('manage-images');
    $eventConfig = EventConfiguration::factory()->create();
    Image::factory()->create(['event_configuration_id' => $eventConfig->id]);

    Livewire::actingAs($user)
        ->test(GalleryIndex::class)
        ->assertSee('Download');
});

test('download button hidden on event cards for users without manage-images permission', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();
    Image::factory()->create(['event_configuration_id' => $eventConfig->id]);

    Livewire::actingAs($user)
        ->test(GalleryIndex::class)
        ->assertDontSee('Download');
});
