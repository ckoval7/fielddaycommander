<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Unit\Services;

use App\Services\ImageResult;
use App\Services\ImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageServiceTest extends TestCase
{
    protected ImageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->service = new ImageService;
    }

    public function test_calculate_hash_returns_sha256_hash(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);

        $hash = $this->service->calculateHash($file);

        expect($hash)->toBeString()
            ->and(strlen($hash))->toBe(64);
    }

    public function test_calculate_hash_returns_same_hash_for_identical_files(): void
    {
        $tempFile = UploadedFile::fake()->image('test.jpg', 100, 100);
        $content = file_get_contents($tempFile->path());

        $file1 = UploadedFile::fake()->createWithContent('test1.jpg', $content);
        $file2 = UploadedFile::fake()->createWithContent('test2.jpg', $content);

        $hash1 = $this->service->calculateHash($file1);
        $hash2 = $this->service->calculateHash($file2);

        expect($hash1)->toBe($hash2);
    }

    public function test_store_saves_file_and_returns_image_result(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 800, 600);

        $result = $this->service->store($file, 'gallery/1');

        expect($result)->toBeInstanceOf(ImageResult::class)
            ->and($result->path)->toStartWith('gallery/1/')
            ->and($result->hash)->toBeString()
            ->and($result->mimeType)->toBe('image/jpeg')
            ->and($result->size)->toBeGreaterThan(0);

        Storage::disk('local')->assertExists($result->path);
    }

    public function test_store_creates_thumbnail(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 800, 600);

        $result = $this->service->store($file, 'gallery/1');

        expect($result->thumbnailPath)->not->toBeNull();
        Storage::disk('local')->assertExists($result->thumbnailPath);
    }

    public function test_delete_removes_file_and_thumbnail(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 800, 600);
        $result = $this->service->store($file, 'gallery/1');

        $deleted = $this->service->delete($result->path, $result->thumbnailPath);

        expect($deleted)->toBeTrue();
        Storage::disk('local')->assertMissing($result->path);
        Storage::disk('local')->assertMissing($result->thumbnailPath);
    }

    public function test_is_valid_image_returns_true_for_valid_images(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);

        expect($this->service->isValidImage($file))->toBeTrue();
    }

    public function test_is_valid_image_returns_false_for_non_images(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        expect($this->service->isValidImage($file))->toBeFalse();
    }

    public function test_is_valid_image_returns_false_for_oversized_files(): void
    {
        $file = UploadedFile::fake()->image('huge.jpg', 100, 100)->size(26000); // 26MB

        expect($this->service->isValidImage($file))->toBeFalse();
    }
}
