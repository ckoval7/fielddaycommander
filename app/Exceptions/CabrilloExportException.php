<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception thrown when a Cabrillo log export fails due to
 * missing or invalid event configuration data.
 */
class CabrilloExportException extends RuntimeException
{
    //
}
