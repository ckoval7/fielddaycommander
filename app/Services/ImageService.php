<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageService
{
    protected const MAX_FILE_SIZE = 25 * 1024 * 1024; // 25MB

    protected const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    protected const THUMBNAIL_WIDTH = 600;

    protected const MAX_THUMBNAIL_HEIGHT = 1200;

    /** Largest decoded image, in pixels, that will be loaded into memory for thumbnailing. */
    public const MAX_PIXELS = 50_000_000;

    /** Widest allowed ratio between an image's long and short sides. */
    public const MAX_ASPECT_RATIO = 20;

    public function calculateHash(UploadedFile $file): string
    {
        return hash_file('sha256', $file->path());
    }

    public function store(UploadedFile $file, string $directory): ImageResult
    {
        $hash = $this->calculateHash($file);
        $hashPrefix = substr($hash, 0, 2);
        $extension = $file->guessExtension() ?: 'jpg';
        $filename = Str::uuid().'.'.$extension;

        $storagePath = "{$directory}/{$hashPrefix}/{$filename}";

        Storage::disk('local')->putFileAs(
            dirname($storagePath),
            $file,
            basename($storagePath)
        );

        $thumbnailPath = $this->generateThumbnail($storagePath, $directory, $hashPrefix, $filename);

        return new ImageResult(
            path: $storagePath,
            thumbnailPath: $thumbnailPath,
            hash: $hash,
            mimeType: $file->getMimeType() ?? 'image/jpeg',
            size: $file->getSize(),
        );
    }

    protected function generateThumbnail(string $originalPath, string $directory, string $hashPrefix, string $filename): ?string
    {
        $thumbFilename = pathinfo($filename, PATHINFO_FILENAME).'_thumb.jpg';
        $thumbnailPath = "{$directory}/{$hashPrefix}/{$thumbFilename}";

        $originalFullPath = Storage::disk('local')->path($originalPath);

        if (! file_exists($originalFullPath)) {
            return null;
        }

        $imageInfo = getimagesize($originalFullPath);
        if ($imageInfo === false) {
            return null;
        }

        [$width, $height, $type] = $imageInfo;

        if (! $this->dimensionsAreAcceptable($width, $height)) {
            return null;
        }

        $source = $this->createImageResource($originalFullPath, $type);

        if ($source === null) {
            return null;
        }

        [$newWidth, $newHeight] = $this->thumbnailDimensions($width, $height);

        $thumb = imagecreatetruecolor($newWidth, $newHeight);

        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $thumbnailFullPath = Storage::disk('local')->path($thumbnailPath);

        $thumbnailDir = dirname($thumbnailFullPath);
        if (! is_dir($thumbnailDir)) {
            mkdir($thumbnailDir, 0755, true);
        }

        imagejpeg($thumb, $thumbnailFullPath, 80);

        imagedestroy($source);
        imagedestroy($thumb);

        return $thumbnailPath;
    }

    /**
     * Whether the image's pixel dimensions are safe to decode for thumbnailing.
     */
    public function hasAcceptableDimensions(UploadedFile $file): bool
    {
        $imageInfo = @getimagesize($file->path());

        if ($imageInfo === false) {
            return false;
        }

        return $this->dimensionsAreAcceptable($imageInfo[0], $imageInfo[1]);
    }

    protected function dimensionsAreAcceptable(int $width, int $height): bool
    {
        if ($width < 1 || $height < 1) {
            return false;
        }

        if ($width * $height > self::MAX_PIXELS) {
            return false;
        }

        return max($width, $height) / min($width, $height) <= self::MAX_ASPECT_RATIO;
    }

    /**
     * Scale to the thumbnail width, shrinking further when the result would be too tall.
     *
     * @return array{0: int, 1: int}
     */
    protected function thumbnailDimensions(int $width, int $height): array
    {
        $newWidth = self::THUMBNAIL_WIDTH;
        $newHeight = (int) round($height * ($newWidth / $width));

        if ($newHeight > self::MAX_THUMBNAIL_HEIGHT) {
            $newHeight = self::MAX_THUMBNAIL_HEIGHT;
            $newWidth = (int) round($width * ($newHeight / $height));
        }

        return [max(1, $newWidth), max(1, $newHeight)];
    }

    /**
     * Create a GD image resource from a file based on its type.
     */
    protected function createImageResource(string $path, int $type): ?\GdImage
    {
        return match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_GIF => imagecreatefromgif($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default => null,
        };
    }

    public function regenerateThumbnail(string $storagePath): ?string
    {
        $directory = dirname($storagePath);
        $hashPrefix = basename($directory);
        $parentDirectory = dirname($directory);
        $filename = basename($storagePath);

        return $this->generateThumbnail($storagePath, $parentDirectory, $hashPrefix, $filename);
    }

    public function delete(string $path, ?string $thumbnailPath = null): bool
    {
        $deleted = Storage::disk('local')->delete($path);

        if ($thumbnailPath) {
            Storage::disk('local')->delete($thumbnailPath);
        }

        return $deleted;
    }

    public function isValidImage(UploadedFile $file): bool
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return false;
        }

        if (! in_array($file->getMimeType(), self::ALLOWED_MIMES, true)) {
            return false;
        }

        return true;
    }
}
