<?php

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
        ->call('upload')
        ->assertDispatched('notify');

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
        ->call('upload');

    // Try to upload duplicate
    Livewire::actingAs($user)
        ->test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->set('photo', $file2)
        ->call('upload')
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
        ->call('upload')
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
        ->call('upload')
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
        ->call('upload')
        ->assertDispatched('notify');

    expect(Image::first()->caption)->toBeNull();
});
