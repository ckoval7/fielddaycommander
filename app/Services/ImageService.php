<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Exceptions\ImageProcessingException;
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

    /** JPEG and WebP quality when re-encoding an original to drop its metadata. */
    protected const REENCODE_QUALITY = 90;

    /** GD truecolor pixels take 4 bytes; the extra byte covers row pointers and encoder buffers. */
    protected const DECODED_BYTES_PER_PIXEL = 5;

    /** Memory left free for the framework while an image is decoded. */
    protected const MEMORY_HEADROOM = 16 * 1024 * 1024;

    /** Largest decoded image, in pixels, that will be loaded into memory for thumbnailing. */
    public const MAX_PIXELS = 50_000_000;

    /** Widest allowed ratio between an image's long and short sides. */
    public const MAX_ASPECT_RATIO = 20;

    public function __construct(protected JpegMetadata $jpeg = new JpegMetadata) {}

    public function calculateHash(UploadedFile $file): string
    {
        return hash_file('sha256', $file->path());
    }

    /**
     * Store an upload with its GPS and other identifying metadata removed, plus a thumbnail.
     *
     * The hash is of the file as uploaded, so uploading the same photo again is still caught.
     *
     * @throws ImageProcessingException When the metadata can't be removed; nothing is stored.
     */
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

        $fullPath = Storage::disk('local')->path($storagePath);

        try {
            $decoded = $this->removeMetadata($fullPath);
        } catch (ImageProcessingException $exception) {
            Storage::disk('local')->delete($storagePath);

            throw $exception;
        }

        if ($decoded !== null) {
            $thumbnailPath = $this->thumbnailPathFor($directory, $hashPrefix, $filename);
            $this->writeThumbnail($decoded['image'], 1, $decoded['hasAlpha'], $thumbnailPath);
            unset($decoded);
        } else {
            $thumbnailPath = $this->generateThumbnail($storagePath, $directory, $hashPrefix, $filename);
        }

        clearstatcache(true, $fullPath);

        return new ImageResult(
            path: $storagePath,
            thumbnailPath: $thumbnailPath,
            hash: $hash,
            mimeType: $file->getMimeType() ?? 'image/jpeg',
            size: filesize($fullPath) ?: $file->getSize(),
        );
    }

    /**
     * Strip GPS and other identifying metadata from a stored original, in place.
     *
     * JPEG, PNG and WebP are decoded and re-encoded with GD, as thumbnails are,
     * which writes no EXIF, XMP or text chunks. A JPEG's EXIF orientation is
     * applied to the pixels first so the photo still displays upright. When a
     * JPEG is too large to decode and rotate within the memory limit, its
     * metadata segments are removed without decoding instead, keeping only the
     * orientation tag. GIFs carry no EXIF and are left as uploaded, since GD
     * would flatten animations.
     *
     * @return array{image: \GdImage, hasAlpha: bool}|null The decoded, upright image for
     *                                                     thumbnailing, or null when it wasn't decoded.
     *
     * @throws ImageProcessingException When the image can't be decoded or written.
     */
    protected function removeMetadata(string $fullPath): ?array
    {
        $imageInfo = @getimagesize($fullPath);

        if ($imageInfo === false) {
            throw new ImageProcessingException('Unreadable image.');
        }

        [$width, $height, $type] = $imageInfo;

        if ($type === IMAGETYPE_GIF) {
            return null;
        }

        if ($type === IMAGETYPE_JPEG) {
            return $this->removeJpegMetadata($fullPath, $width, $height);
        }

        if (! in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || ! $this->canDecode($width, $height, false)) {
            throw new ImageProcessingException('Image too large or unsupported.');
        }

        $image = $this->createImageResource($fullPath, $type);

        if ($image === null) {
            throw new ImageProcessingException('Image could not be decoded.');
        }

        imagesavealpha($image, true);

        $temporaryPath = $fullPath.'.tmp';
        $written = $type === IMAGETYPE_PNG
            ? imagepng($image, $temporaryPath)
            : imagewebp($image, $temporaryPath, self::REENCODE_QUALITY);

        if (! $written || ! rename($temporaryPath, $fullPath)) {
            @unlink($temporaryPath);

            throw new ImageProcessingException('Image could not be written.');
        }

        return ['image' => $image, 'hasAlpha' => true];
    }

    /**
     * @return array{image: \GdImage, hasAlpha: bool}|null
     *
     * @throws ImageProcessingException When the JPEG can't be cleaned either way.
     */
    protected function removeJpegMetadata(string $fullPath, int $width, int $height): ?array
    {
        $orientation = $this->jpeg->orientation($fullPath);
        $temporaryPath = $fullPath.'.tmp';

        // The colour profile copy holds the original and re-encoded bytes alongside the pixels.
        $fileBytes = 3 * (int) filesize($fullPath);

        if ($this->canDecode($width, $height, self::orientationSwapsAxes($orientation), $fileBytes)) {
            $image = $this->createImageResource($fullPath, IMAGETYPE_JPEG);

            if ($image !== null) {
                $image = $this->applyOrientation($image, $orientation);

                if (imagejpeg($image, $temporaryPath, self::REENCODE_QUALITY)) {
                    $this->jpeg->copyColourProfile($fullPath, $temporaryPath);

                    if (rename($temporaryPath, $fullPath)) {
                        return ['image' => $image, 'hasAlpha' => false];
                    }
                }

                unset($image);
            }
        }

        if ($this->jpeg->strip($fullPath, $temporaryPath, $orientation) && rename($temporaryPath, $fullPath)) {
            return null;
        }

        @unlink($temporaryPath);

        throw new ImageProcessingException('JPEG metadata could not be removed.');
    }

    /**
     * Whether decoding the image, and a turned copy if needed, fits in the memory limit.
     */
    protected function canDecode(int $width, int $height, bool $needsTurnedCopy, int $extraBytes = 0): bool
    {
        $limit = $this->memoryLimitBytes();

        if ($limit < 0) {
            return true;
        }

        $needed = $width * $height * self::DECODED_BYTES_PER_PIXEL * ($needsTurnedCopy ? 2 : 1);

        return memory_get_usage() + $needed + $extraBytes + self::MEMORY_HEADROOM <= $limit;
    }

    protected function memoryLimitBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return -1;
        }

        $value = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Whether an EXIF orientation turns the image a quarter turn.
     */
    protected static function orientationSwapsAxes(int $orientation): bool
    {
        return $orientation >= 5 && $orientation <= 8;
    }

    /**
     * Turn and mirror the pixels so the image displays upright without its EXIF orientation.
     */
    protected function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        if ($orientation === 3) {
            imageflip($image, IMG_FLIP_BOTH);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        $turned = match ($orientation) {
            5, 8 => imagerotate($image, 90, 0),
            6, 7 => imagerotate($image, 270, 0),
            default => $image,
        };

        return $turned === false ? $image : $turned;
    }

    protected function thumbnailPathFor(string $directory, string $hashPrefix, string $filename): string
    {
        return "{$directory}/{$hashPrefix}/".pathinfo($filename, PATHINFO_FILENAME).'_thumb.jpg';
    }

    protected function generateThumbnail(string $originalPath, string $directory, string $hashPrefix, string $filename): ?string
    {
        $thumbnailPath = $this->thumbnailPathFor($directory, $hashPrefix, $filename);

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

        $orientation = $type === IMAGETYPE_JPEG ? $this->jpeg->orientation($originalFullPath) : 1;

        $this->writeThumbnail($source, $orientation, $type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF, $thumbnailPath);

        return $thumbnailPath;
    }

    /**
     * Scale an image down to a JPEG thumbnail, turning it upright after scaling
     * so the rotation only ever touches the small copy.
     */
    protected function writeThumbnail(\GdImage $source, int $orientation, bool $hasAlpha, string $thumbnailPath): void
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $swapsAxes = self::orientationSwapsAxes($orientation);

        [$uprightWidth, $uprightHeight] = $this->thumbnailDimensions(
            $swapsAxes ? $height : $width,
            $swapsAxes ? $width : $height,
        );
        [$newWidth, $newHeight] = $swapsAxes ? [$uprightHeight, $uprightWidth] : [$uprightWidth, $uprightHeight];

        $thumb = imagecreatetruecolor($newWidth, $newHeight);

        if ($hasAlpha) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        $thumb = $this->applyOrientation($thumb, $orientation);

        $thumbnailFullPath = Storage::disk('local')->path($thumbnailPath);

        $thumbnailDir = dirname($thumbnailFullPath);
        if (! is_dir($thumbnailDir)) {
            mkdir($thumbnailDir, 0755, true);
        }

        imagejpeg($thumb, $thumbnailFullPath, 80);
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
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => null,
        };

        return $image instanceof \GdImage ? $image : null;
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
