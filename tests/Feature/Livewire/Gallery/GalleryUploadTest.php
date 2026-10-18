<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Gallery\GalleryUpload;
use App\Models\EventConfiguration;
use App\Models\Image;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

test('gallery upload requires authentication', function () {
    $eventConfig = EventConfiguration::factory()->create();

    Livewire::test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->assertForbidden();
});

test('authenticated user can access upload page', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->assertStatus(200);
});

test('user can upload a valid image', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);

    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->set('photo', $file)
        ->set('caption', 'Test caption')
        ->call('save')
        ->assertRedirect();

    expect(session('toast.title'))->toBe('Success');

    expect(Image::count())->toBe(1);
    expect(Image::first()->caption)->toBe('Test caption');
    expect(Image::first()->uploaded_by_user_id)->toBe($user->id);
});

test('duplicate file upload is rejected', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    $tempFile = UploadedFile::fake()->image('test.jpg', 100, 100);
    $tempPath = $tempFile->path();
    $content = file_get_contents($tempPath);

    $file1 = UploadedFile::fake()->createWithContent('test1.jpg', $content);
    $file2 = UploadedFile::fake()->createWithContent('test2.jpg', $content);

    // Upload first file
    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->set('photo', $file1)
        ->call('save');

    // Try to upload duplicate
    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->set('photo', $file2)
        ->call('save')
        ->assertHasErrors(['photo']);

    expect(Image::count())->toBe(1);
});

test('invalid file type is rejected', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();
    $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->set('photo', $file)
        ->call('save')
        ->assertHasErrors(['photo']);

    expect(Image::count())->toBe(0);
});

test('oversized file is rejected', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();
    $file = UploadedFile::fake()->image('huge.jpg')->size(26000); // 26MB

    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->set('photo', $file)
        ->call('save')
        ->assertHasErrors(['photo']);

    expect(Image::count())->toBe(0);
});

test('caption is optional', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);

    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->set('photo', $file)
        ->call('save')
        ->assertRedirect();

    expect(session('toast.title'))->toBe('Success');

    expect(Image::first()->caption)->toBeNull();
});

/**
 * Raw bytes of a single small JPEG, so two uploads share one hash.
 */
function sharedJpegContent(): string
{
    $image = UploadedFile::fake()->image('test.jpg', 100, 100);

    return file_get_contents($image->path());
}

/**
 * A PNG header claiming the given dimensions; enough for getimagesize() without allocating pixels.
 */
function pngHeaderClaiming(int $width, int $height): string
{
    $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

    return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
}

test('re-uploading a deleted photo adds it as a new photo', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();
    $content = sharedJpegContent();

    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->set('photo', UploadedFile::fake()->createWithContent('test1.jpg', $content))
        ->set('caption', 'Original caption')
        ->call('save');

    $deleted = Image::first();
    $deleted->delete();

    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->set('photo', UploadedFile::fake()->createWithContent('test2.jpg', $content))
        ->set('caption', 'New caption')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(session('toast'))->toMatchArray(['title' => 'Success', 'description' => 'Photo uploaded successfully!', 'type' => 'success']);

    $new = Image::sole();

    expect($new->id)->not->toBe($deleted->id)
        ->and($new->caption)->toBe('New caption')
        ->and($new->storage_path)->not->toBe($deleted->storage_path)
        ->and(Image::onlyTrashed()->sole()->caption)->toBe('Original caption');
});

test('the same photo can be uploaded to a different event', function () {
    $user = User::factory()->create();
    $firstEvent = EventConfiguration::factory()->create();
    $secondEvent = EventConfiguration::factory()->create();
    $content = sharedJpegContent();

    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $firstEvent])
        ->set('photo', UploadedFile::fake()->createWithContent('test1.jpg', $content))
        ->call('save');

    Image::first()->delete();

    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $secondEvent])
        ->set('photo', UploadedFile::fake()->createWithContent('test2.jpg', $content))
        ->call('save')
        ->assertHasNoErrors();

    expect(Image::where('event_configuration_id', $secondEvent->id)->count())->toBe(1)
        ->and(Image::onlyTrashed()->where('event_configuration_id', $firstEvent->id)->count())->toBe(1);
});

test('image with too many pixels is rejected before anything is stored', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->set('photo', UploadedFile::fake()->createWithContent('huge.png', pngHeaderClaiming(10000, 10000)))
        ->call('save')
        ->assertHasErrors(['photo']);

    expect(Image::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('gallery'))->toBe([]);
});

test('image with an extreme aspect ratio is rejected', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->set('photo', UploadedFile::fake()->image('strip.png', 3000, 100))
        ->call('save')
        ->assertHasErrors(['photo']);

    expect(Image::count())->toBe(0);
});

test('tall images get a thumbnail with bounded height', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->set('photo', UploadedFile::fake()->image('tall.jpg', 100, 1000))
        ->call('save')
        ->assertHasNoErrors();

    $thumbnail = collect(Storage::disk('local')->allFiles('gallery'))
        ->first(fn (string $path): bool => str_ends_with($path, '_thumb.jpg'));

    [$width, $height] = getimagesize(Storage::disk('local')->path($thumbnail));

    expect($height)->toBe(1200)
        ->and($width)->toBe(120);
});
