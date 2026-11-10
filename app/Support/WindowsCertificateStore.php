<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use Illuminate\Http\Client\Factory;

/**
 * PHP for Windows ships no root certificate bundle, so every HTTPS request
 * (weather, the leaked-password check) fails certificate verification. On
 * Windows, curl is told to trust the Windows certificate store instead,
 * which stays current with Windows Update and includes any certificates
 * the club has installed.
 */
final class WindowsCertificateStore
{
    private function __construct()
    {
        // Static helpers only.
    }

    public static function apply(Factory $http, string $osFamily = PHP_OS_FAMILY): void
    {
        if ($osFamily !== 'Windows' || ! defined('CURLSSLOPT_NATIVE_CA')) {
            return;
        }

        $http->globalOptions(['curl' => [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA]]);
    }
}
