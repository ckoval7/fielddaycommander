<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an operator can't move their session to another band or mode.
 * The message is shown to the operator.
 */
class BandModeSwitchException extends RuntimeException {}
