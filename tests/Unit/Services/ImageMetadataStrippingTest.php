<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Exceptions\ImageProcessingException;
use App\Services\ImageService;
use App\Services\JpegMetadata;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A 40x20 JPEG, red on the left half and blue on the right, tagged with an EXIF
 * orientation, GPS coordinates, an XMP packet, a comment and a trailing preview.
 */
function photoWithGps(int $orientation = 6): string
{
    $image = imagecreatetruecolor(40, 20);
    imagefilledrectangle($image, 0, 0, 19, 19, imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, 20, 0, 39, 19, imagecolorallocate($image, 0, 0, 255));

    ob_start();
    imagejpeg($image, null, 95);
    $jpeg = (string) ob_get_clean();

    $tiff = "MM\0\x2A".pack('N', 8)
        .pack('n', 2)
        .pack('nnNn', 0x0112, 3, 1, $orientation)."\0\0"
        .pack('nnNN', 0x8825, 4, 1, 38)
        .pack('N', 0)
        .pack('n', 1)
        .pack('nnN', 0x0001, 2, 2)."N\0\0\0"
        .pack('N', 0);
    $exif = "Exif\0\0".$tiff;
    $xmp = "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta><exif:GPSLatitude>41,30.0N</exif:GPSLatitude></x:xmpmeta>";
    $comment = 'Shot at the club secret site';

    $segments = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif
        ."\xFF\xE1".pack('n', strlen($xmp) + 2).$xmp
        ."\xFF\xFE".pack('n', strlen($comment) + 2).$comment;

    return substr($jpeg, 0, 2).$segments.substr($jpeg, 2).'TRAILING-PREVIEW-GPS';
}

function storedBytes(string $path): string
{
    return (string) file_get_contents(Storage::disk('local')->path($path));
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function rgbAt(string $path, int $x, int $y): array
{
    $image = imagecreatefromstring(storedBytes($path));
    $rgb = imagecolorat($image, $x, $y);

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

beforeEach(function () {
    Storage::fake('local');
});

test('stored JPEG has its EXIF, GPS, XMP and comments removed', function () {
    $file = UploadedFile::fake()->createWithContent('photo.jpg', photoWithGps());

    $result = (new ImageService)->store($file, 'gallery/1');
    $bytes = storedBytes($result->path);

    expect($bytes)->not->toContain("Exif\0\0")
        ->and($bytes)->not->toContain('GPSLatitude')
        ->and($bytes)->not->toContain('club secret site')
        ->and($bytes)->not->toContain('TRAILING-PREVIEW-GPS')
        ->and($result->size)->toBe(strlen($bytes))
        ->and($result->hash)->toBe(hash('sha256', photoWithGps()));
});

test('stored JPEG is turned upright before its orientation tag is dropped', function () {
    $file = UploadedFile::fake()->createWithContent('photo.jpg', photoWithGps(6));

    $result = (new ImageService)->store($file, 'gallery/1');

    [$width, $height] = getimagesizefromstring(storedBytes($result->path));

    expect($width)->toBe(20)->and($height)->toBe(40);

    [$topRed, , $topBlue] = rgbAt($result->path, 10, 5);
    [$bottomRed, , $bottomBlue] = rgbAt($result->path, 10, 35);

    expect($topRed)->toBeGreaterThan(200)->and($topBlue)->toBeLessThan(60)
        ->and($bottomBlue)->toBeGreaterThan(200)->and($bottomRed)->toBeLessThan(60);

    [$thumbWidth, $thumbHeight] = getimagesizefromstring(storedBytes($result->thumbnailPath));
    expect($thumbWidth)->toBeLessThan($thumbHeight);
});

test('mirrored orientations are applied too', function () {
    $file = UploadedFile::fake()->createWithContent('photo.jpg', photoWithGps(2));

    $result = (new ImageService)->store($file, 'gallery/1');

    [$leftRed, , $leftBlue] = rgbAt($result->path, 5, 10);

    expect($leftBlue)->toBeGreaterThan(200)->and($leftRed)->toBeLessThan(60);
});

test('a JPEG too large to decode is stripped without decoding and keeps only its orientation', function () {
    $service = new class extends ImageService
    {
        protected function canDecode(int $width, int $height, bool $needsTurnedCopy, int $extraBytes = 0): bool
        {
            return false;
        }
    };
    $file = UploadedFile::fake()->createWithContent('photo.jpg', photoWithGps(6));

    $result = $service->store($file, 'gallery/1');
    $bytes = storedBytes($result->path);
    $exifAt = strpos($bytes, "Exif\0\0");

    expect($bytes)->not->toContain('GPSLatitude')
        ->and($bytes)->not->toContain('club secret site')
        ->and($bytes)->not->toContain('TRAILING-PREVIEW-GPS')
        ->and(substr_count($bytes, "Exif\0\0"))->toBe(1)
        ->and(unpack('n', substr($bytes, $exifAt - 2, 2))[1])->toBe(34)
        ->and((new JpegMetadata)->orientation(Storage::disk('local')->path($result->path)))->toBe(6)
        ->and(getimagesizefromstring($bytes)[0])->toBe(40);

    [$thumbWidth, $thumbHeight] = getimagesizefromstring(storedBytes($result->thumbnailPath));
    [$topRed, , $topBlue] = rgbAt($result->thumbnailPath, (int) ($thumbWidth / 2), (int) ($thumbHeight / 8));

    expect($thumbWidth)->toBeLessThan($thumbHeight)
        ->and($topRed)->toBeGreaterThan(200)->and($topBlue)->toBeLessThan(60);
});

test('stored PNG has its text metadata removed', function () {
    $image = imagecreatetruecolor(30, 30);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    $text = "Comment\0GPSLatitude 41.5N";
    $chunk = pack('N', strlen($text)).'tEXt'.$text.pack('N', crc32('tEXt'.$text));
    $png = substr($png, 0, 33).$chunk.substr($png, 33);

    $file = UploadedFile::fake()->createWithContent('photo.png', $png);

    $result = (new ImageService)->store($file, 'gallery/1');

    expect(storedBytes($result->path))->not->toContain('GPSLatitude')
        ->and(getimagesizefromstring(storedBytes($result->path))[0])->toBe(30);
});

test('an image that cannot be decoded is refused and nothing is kept', function () {
    $image = imagecreatetruecolor(30, 30);
    ob_start();
    imagepng($image);
    $png = substr((string) ob_get_clean(), 0, 40).str_repeat("\0", 40);

    $file = UploadedFile::fake()->createWithContent('broken.png', $png);

    expect(fn () => (new ImageService)->store($file, 'gallery/1'))
        ->toThrow(ImageProcessingException::class);

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});
