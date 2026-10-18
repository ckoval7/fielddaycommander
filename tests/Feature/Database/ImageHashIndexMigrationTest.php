<?php

use App\Models\Image;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_10_17_175024_scope_image_file_hash_unique_to_event.php');
});

test('rolling back restores the global unique hash index when hashes are distinct', function () {
    $image = Image::factory()->create();

    $this->migration->down();

    expect(fn () => DB::table('images')->insert(
        collect($image->getAttributes())->except('id')->all()
    ))->toThrow(UniqueConstraintViolationException::class);

    $this->migration->up();
});

test('rolling back still completes when the same photo exists more than once', function () {
    $image = Image::factory()->create();
    Image::factory()->create(['file_hash' => $image->file_hash]);

    $this->migration->down();

    expect(DB::table('images')->where('file_hash', $image->file_hash)->count())->toBe(2);

    $this->migration->up();
});
