<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('command deletes expired export zips', function () {
    $path = 'exports/gallery/1/album-old.zip';
    Storage::disk('local')->put($path, 'old-zip-content');

    // Backdate the file to 25 hours ago
    $absolutePath = Storage::disk('local')->path($path);
    touch($absolutePath, time() - 90000);

    $this->artisan('exports:clean')
        ->expectsOutputToContain('Deleted')
        ->assertSuccessful();

    Storage::disk('local')->assertMissing($path);
});

test('command preserves recent export zips', function () {
    $path = 'exports/gallery/1/album-recent.zip';
    Storage::disk('local')->put($path, 'recent-zip-content');

    $this->artisan('exports:clean')
        ->assertSuccessful();

    Storage::disk('local')->assertExists($path);
});

test('command handles empty exports directory', function () {
    $this->artisan('exports:clean')
        ->assertSuccessful();
});
