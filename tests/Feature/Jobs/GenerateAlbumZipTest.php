<?php

use App\Jobs\GenerateAlbumZip;
use App\Models\EventConfiguration;
use App\Models\Image;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
});

test('job creates a zip file with all event images', function () {
    $eventConfig = EventConfiguration::factory()->create();
    $user = User::factory()->create();

    $images = Image::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
    ]);

    foreach ($images as $image) {
        Storage::disk('local')->put($image->storage_path, 'fake-image-content-'.$image->id);
    }

    $job = new GenerateAlbumZip($eventConfig->id, $user->id);
    $job->handle();

    $files = Storage::disk('local')->files("exports/gallery/{$eventConfig->id}");
    expect($files)->toHaveCount(1);
    expect($files[0])->toMatch('/album-\d+\.zip$/');
});

test('job handles duplicate filenames by appending counter', function () {
    $eventConfig = EventConfiguration::factory()->create();
    $user = User::factory()->create();

    $image1 = Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'filename' => 'photo.jpg',
    ]);
    $image2 = Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'filename' => 'photo.jpg',
    ]);

    Storage::disk('local')->put($image1->storage_path, 'content-1');
    Storage::disk('local')->put($image2->storage_path, 'content-2');

    $job = new GenerateAlbumZip($eventConfig->id, $user->id);
    $job->handle();

    $files = Storage::disk('local')->files("exports/gallery/{$eventConfig->id}");
    $zipPath = Storage::disk('local')->path($files[0]);

    $zip = new ZipArchive;
    $zip->open($zipPath);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    expect($names)->toContain('photo.jpg');
    expect($names)->toContain('photo-2.jpg');
});

test('job sends notification on success', function () {
    $eventConfig = EventConfiguration::factory()->create();
    $user = User::factory()->create();

    $image = Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
    ]);
    Storage::disk('local')->put($image->storage_path, 'fake-content');

    $job = new GenerateAlbumZip($eventConfig->id, $user->id);
    $job->handle();

    Notification::assertSentTo($user, \App\Notifications\InAppNotification::class);
});

test('job notifies user when no images exist', function () {
    $eventConfig = EventConfiguration::factory()->create();
    $user = User::factory()->create();

    $job = new GenerateAlbumZip($eventConfig->id, $user->id);
    $job->handle();

    Notification::assertSentTo($user, \App\Notifications\InAppNotification::class, function ($notification) {
        return str_contains($notification->message, 'No photos');
    });

    $files = Storage::disk('local')->files("exports/gallery/{$eventConfig->id}");
    expect($files)->toBeEmpty();
});

test('job skips soft-deleted images', function () {
    $eventConfig = EventConfiguration::factory()->create();
    $user = User::factory()->create();

    $activeImage = Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'filename' => 'active.jpg',
    ]);
    $deletedImage = Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'filename' => 'deleted.jpg',
    ]);
    $deletedImage->delete();

    Storage::disk('local')->put($activeImage->storage_path, 'active-content');

    $job = new GenerateAlbumZip($eventConfig->id, $user->id);
    $job->handle();

    $files = Storage::disk('local')->files("exports/gallery/{$eventConfig->id}");
    $zipPath = Storage::disk('local')->path($files[0]);

    $zip = new ZipArchive;
    $zip->open($zipPath);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    expect($names)->toContain('active.jpg');
    expect($names)->not->toContain('deleted.jpg');
});
