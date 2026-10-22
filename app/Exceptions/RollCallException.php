<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception thrown when a roll call can't be started, such as on a net with
 * no stations to call.
 */
class RollCallException extends RuntimeException {}
