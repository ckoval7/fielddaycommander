<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

/**
 * Laravel writes compiled views and inline component templates by renaming
 * a temporary file over the real one. Windows refuses ("Access is denied")
 * while another request has that file open, so when several people opened
 * pages right after an install or upgrade, most of them got a 500. Every
 * request writes the same content, so when the file is already in place the
 * failed rename is harmless: its temporary file is removed and the warning
 * is dropped. The same goes for the compiler's touch() that marks an
 * unchanged view as fresh, which simply happens again on a later request. Some of these writes use a hard-coded new Filesystem, so this
 * has to happen in the error handler rather than in a filesystem binding.
 */
final class WindowsViewRace
{
    private function __construct()
    {
        // Static helpers only.
    }

    /**
     * Wrap the current error handler (Laravel's) so lost races are ignored.
     */
    public static function register(string $compiledPath): void
    {
        $previous = null;
        $previous = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previous, $compiledPath): bool {
            if (self::isLostRace($level, $message, $compiledPath, $file)) {
                return true;
            }

            return $previous === null || $previous($level, $message, $file, $line) !== false;
        });
    }

    /**
     * Whether a warning is a view write that lost to another request writing the
     * same file. For a rename, the temporary file is removed.
     */
    public static function isLostRace(int $level, string $message, string $compiledPath, string $file = ''): bool
    {
        if ($level !== E_WARNING) {
            return false;
        }

        // touch() names no path, so it's recognised by where it was called.
        if (str_starts_with($message, 'touch(): Utime failed') && str_ends_with(self::normalize($file), 'illuminate/view/compilers/bladecompiler.php')) {
            return true;
        }

        if (preg_match('/^rename\((.+),(.+)\): Access is denied/', $message, $match) !== 1) {
            return false;
        }

        [, $temporary, $target] = $match;
        $folder = self::normalize($compiledPath).'/';

        if (! str_starts_with(self::normalize($target), $folder) || ! str_starts_with(self::normalize($temporary), $folder)) {
            return false;
        }

        clearstatcache(true, $target);

        if (! is_file($target)) {
            return false;
        }

        @unlink($temporary);

        return true;
    }

    private static function normalize(string $path): string
    {
        return rtrim(strtolower(str_replace('\\', '/', $path)), '/');
    }
}
