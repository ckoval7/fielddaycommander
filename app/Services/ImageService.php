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

    public function calculateHash(UploadedFile $file): string
    {
        return hash_file('sha256', $file->path());
    }

    public function store(UploadedFile $file, string $directory): ImageResult
    {
        $hash = $this->calculateHash($file);
        $hashPrefix = substr($hash, 0, 2);
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
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

        $source = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($originalFullPath),
            IMAGETYPE_PNG => imagecreatefrompng($originalFullPath),
            IMAGETYPE_GIF => imagecreatefromgif($originalFullPath),
            IMAGETYPE_WEBP => imagecreatefromwebp($originalFullPath),
            default => null,
        };

        if ($source === null) {
            return null;
        }

        $newWidth = self::THUMBNAIL_WIDTH;
        $newHeight = (int) ($height * ($newWidth / $width));

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

    public function getAllowedMimeTypes(): array
    {
        return self::ALLOWED_MIMES;
    }

    public function getMaxFileSize(): int
    {
        return self::MAX_FILE_SIZE;
    }
}
