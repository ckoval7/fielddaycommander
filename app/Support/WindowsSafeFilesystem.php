<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Laravel writes compiled views by renaming a temporary file over the old
 * one. Windows refuses that while another request has the file open, so
 * when several requests compile the same view at once (right after an
 * install or upgrade) some of them failed with a 500. Every request
 * compiles the same content, so if the file is already in place the
 * duplicate copy is dropped instead.
 */
class WindowsSafeFilesystem extends Filesystem
{
    public function replace($path, $content, $mode = null)
    {
        clearstatcache(true, $path);

        $path = realpath($path) ?: $path;
        $tempPath = tempnam(dirname($path), basename($path));

        @chmod($tempPath, $mode ?? 0777 - umask());
        file_put_contents($tempPath, $content);

        if ($this->moveIntoPlace($tempPath, $path)) {
            return;
        }

        @unlink($tempPath);
        clearstatcache(true, $path);

        if (! is_file($path)) {
            throw new RuntimeException("Could not write {$path}.");
        }
    }

    /**
     * Rename the temporary file over the target, reporting failure instead of warning.
     */
    protected function moveIntoPlace(string $tempPath, string $path): bool
    {
        return @rename($tempPath, $path);
    }
}
