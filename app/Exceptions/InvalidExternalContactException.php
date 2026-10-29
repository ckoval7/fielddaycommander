<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an external logger sends a contact that fails validation
 * (e.g. a callsign with characters no callsign contains, or an HF band at
 * the free VHF station), so the packet is dropped.
 */
class InvalidExternalContactException extends RuntimeException
{
    /**
     * @param  string  $rejectionReason  Short reason recorded as the listener's last log entry.
     */
    public function __construct(
        string $message = '',
        public readonly string $rejectionReason = 'invalid callsign',
    ) {
        parent::__construct($message);
    }
}
