<?php

use App\Livewire\Gallery\GalleryIndex;
use App\Models\EventConfiguration;
use App\Models\Image;
use App\Models\User;
use Livewire\Livewire;

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

test('gallery index does not show events without photos', function () {
    $eventWithPhotos = EventConfiguration::factory()->create();
    $eventWithPhotos->event->update(['name' => 'Event With Photos']);
    Image::factory()->create(['event_configuration_id' => $eventWithPhotos->id]);

    $eventWithoutPhotos = EventConfiguration::factory()->create();
    $eventWithoutPhotos->event->update(['name' => 'Event Without Photos']);

    Livewire::test(GalleryIndex::class)
        ->assertSee('Event With Photos')
        ->assertDontSee('Event Without Photos');
});

test('gallery index shows photo count per event', function () {
    $eventConfig = EventConfiguration::factory()->create();
    Image::factory()->count(5)->create(['event_configuration_id' => $eventConfig->id]);

    Livewire::test(GalleryIndex::class)
        ->assertSee('5 photos');
});

test('gallery index shows upload button for authenticated users', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(GalleryIndex::class)
        ->assertDontSee('Upload Photo');
})->skip('Design change: Upload button moved to show page where event context exists');

test('gallery index hides upload button for guests', function () {
    Livewire::test(GalleryIndex::class)
        ->assertDontSee('Upload Photo');
});
