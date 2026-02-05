<?php

use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    // Mark system as set up
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('thumbnail route returns image', function () {
    $image = Image::factory()->create();

    // Create a fake thumbnail file
    Storage::disk('local')->put(
        str_replace('.jpg', '_thumb.jpg', $image->storage_path),
        file_get_contents(base_path('tests/fixtures/test-image.jpg'))
    );

    $response = $this->get(route('gallery.thumb', $image));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'image/jpeg');
});

test('full image route returns image', function () {
    $image = Image::factory()->create();

    Storage::disk('local')->put(
        $image->storage_path,
        file_get_contents(base_path('tests/fixtures/test-image.jpg'))
    );

    $response = $this->get(route('gallery.image', $image));

    $response->assertStatus(200);
});

test('thumbnail returns 404 for soft deleted image', function () {
    $image = Image::factory()->create();
    $image->delete();

    $response = $this->get(route('gallery.thumb', $image));

    $response->assertStatus(404);
});
