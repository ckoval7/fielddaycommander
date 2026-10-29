<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

/**
 * Reads and removes JPEG metadata segments without decoding the image.
 *
 * Works on the raw marker structure, so it needs no exif extension and only
 * holds the file's bytes in memory, never the decoded pixels.
 */
class JpegMetadata
{
    private const MARKER_APP0 = 0xE0;

    private const MARKER_APP1 = 0xE1;

    private const MARKER_APP2 = 0xE2;

    private const MARKER_APP14 = 0xEE;

    private const MARKER_SOS = 0xDA;

    private const TAG_ORIENTATION = 0x0112;

    /**
     * The EXIF orientation (1-8) of a JPEG file, or 1 when it has none.
     */
    public function orientation(string $path): int
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            return 1;
        }

        foreach ($this->segments($bytes)['segments'] as [$marker, $segment]) {
            if ($marker === self::MARKER_APP1 && substr($segment, 4, 6) === "Exif\0\0") {
                return $this->parseOrientation(substr($segment, 10));
            }
        }

        return 1;
    }

    /**
     * Rewrite a JPEG with every metadata segment removed.
     *
     * Keeps only what decoding and colour need: the JFIF header, ICC colour
     * profile and Adobe colour transform. EXIF, XMP, IPTC, comments and any
     * data after the end-of-image marker (such as embedded previews, which
     * carry their own EXIF) are dropped. A non-default orientation is written
     * back as a minimal EXIF block holding nothing but that tag.
     *
     * @return bool False when the file isn't a JPEG this parser understands.
     */
    public function strip(string $sourcePath, string $destinationPath, int $orientation = 1): bool
    {
        $bytes = @file_get_contents($sourcePath);

        if ($bytes === false) {
            return false;
        }

        $parsed = $this->segments($bytes);

        if ($parsed['scanStart'] === null) {
            return false;
        }

        $app0 = '';
        $kept = '';

        foreach ($parsed['segments'] as [$marker, $segment]) {
            if ($marker === self::MARKER_APP0) {
                $app0 .= $segment;
            } elseif ($this->isKeptSegment($marker, $segment)) {
                $kept .= $segment;
            }
        }

        $exif = $orientation === 1 ? '' : $this->orientationSegment($orientation);

        $end = strpos($bytes, "\xFF\xD9", $parsed['scanStart']);
        $scan = $end === false
            ? substr($bytes, $parsed['scanStart'])
            : substr($bytes, $parsed['scanStart'], $end + 2 - $parsed['scanStart']);

        return file_put_contents($destinationPath, "\xFF\xD8".$app0.$exif.$kept.$scan) !== false;
    }

    /**
     * Copy the ICC colour profile segments from one JPEG into another.
     *
     * GD drops the profile when it re-encodes, which shifts the colours of
     * wide-gamut phone photos.
     */
    public function copyColourProfile(string $sourcePath, string $destinationPath): void
    {
        $source = @file_get_contents($sourcePath);
        $destination = @file_get_contents($destinationPath);

        if ($source === false || $destination === false) {
            return;
        }

        $profile = '';

        foreach ($this->segments($source)['segments'] as [$marker, $segment]) {
            if ($marker === self::MARKER_APP2 && $this->isColourProfile($segment)) {
                $profile .= $segment;
            }
        }

        if ($profile === '') {
            return;
        }

        $insertAt = 2;

        foreach ($this->segments($destination)['segments'] as [$marker, $segment]) {
            if ($marker !== self::MARKER_APP0) {
                break;
            }

            $insertAt += strlen($segment);
        }

        file_put_contents($destinationPath, substr($destination, 0, $insertAt).$profile.substr($destination, $insertAt));
    }

    /**
     * Split the header of a JPEG into its marker segments, up to the first scan.
     *
     * @return array{segments: list<array{0: int, 1: string}>, scanStart: int|null}
     */
    private function segments(string $bytes): array
    {
        $segments = [];
        $length = strlen($bytes);

        if (! str_starts_with($bytes, "\xFF\xD8")) {
            return ['segments' => [], 'scanStart' => null];
        }

        $position = 2;

        while ($position + 4 <= $length) {
            if ($bytes[$position] !== "\xFF") {
                return ['segments' => $segments, 'scanStart' => null];
            }

            $start = $position;

            while ($position < $length && $bytes[$position] === "\xFF") {
                $position++;
            }

            if ($position + 3 > $length) {
                break;
            }

            $marker = ord($bytes[$position]);

            if ($marker === self::MARKER_SOS) {
                return ['segments' => $segments, 'scanStart' => $start];
            }

            $segmentLength = unpack('n', substr($bytes, $position + 1, 2))[1];
            $end = $position + 1 + $segmentLength;

            if ($segmentLength < 2 || $end > $length) {
                break;
            }

            $segments[] = [$marker, "\xFF".chr($marker).substr($bytes, $position + 1, $segmentLength)];
            $position = $end;
        }

        return ['segments' => $segments, 'scanStart' => null];
    }

    /**
     * Whether a non-APP0 header segment survives stripping.
     */
    private function isKeptSegment(int $marker, string $segment): bool
    {
        if ($marker === self::MARKER_APP2) {
            return $this->isColourProfile($segment);
        }

        if ($marker === self::MARKER_APP14) {
            return substr($segment, 4, 5) === 'Adobe';
        }

        // Every other APPn (0xE1-0xEF) and comments (0xFE) carry metadata.
        return ! (($marker >= 0xE1 && $marker <= 0xEF) || $marker === 0xFE);
    }

    private function isColourProfile(string $segment): bool
    {
        return substr($segment, 4, 12) === "ICC_PROFILE\0";
    }

    /**
     * Read the Orientation tag from IFD0 of a TIFF-structured EXIF block.
     */
    private function parseOrientation(string $tiff): int
    {
        $order = substr($tiff, 0, 2);

        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }

        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';

        if (strlen($tiff) < 8) {
            return 1;
        }

        $ifdOffset = unpack($long, substr($tiff, 4, 4))[1];

        if ($ifdOffset + 2 > strlen($tiff)) {
            return 1;
        }

        $entries = unpack($short, substr($tiff, $ifdOffset, 2))[1];

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifdOffset + 2 + ($i * 12);

            if ($entry + 12 > strlen($tiff)) {
                return 1;
            }

            if (unpack($short, substr($tiff, $entry, 2))[1] === self::TAG_ORIENTATION) {
                $value = unpack($short, substr($tiff, $entry + 8, 2))[1];

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /**
     * An APP1 EXIF segment containing only the Orientation tag.
     */
    private function orientationSegment(int $orientation): string
    {
        $tiff = "MM\0\x2A".pack('N', 8)
            .pack('n', 1)
            .pack('nnNn', self::TAG_ORIENTATION, 3, 1, $orientation)."\0\0"
            .pack('N', 0);

        $payload = "Exif\0\0".$tiff;

        return "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    }
}
