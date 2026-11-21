<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception thrown at boot when an auth-security setting has an unknown value.
 */
class InvalidAuthSecurityConfigException extends RuntimeException {}
