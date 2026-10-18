<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an external logger sends a contact that fails validation
 * (e.g. a callsign with characters no callsign contains), so the packet is dropped.
 */
class InvalidExternalContactException extends RuntimeException {}
