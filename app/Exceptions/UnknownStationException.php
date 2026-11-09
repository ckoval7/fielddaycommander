<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a contact names a station that doesn't exist and its sender
 * isn't allowed to create one.
 */
class UnknownStationException extends RuntimeException {}
