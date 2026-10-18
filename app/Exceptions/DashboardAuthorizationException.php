<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception thrown when a user attempts to perform an unauthorized
 * action on a dashboard they do not own.
 */
class DashboardAuthorizationException extends RuntimeException
{
    //
}
