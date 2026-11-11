<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Exceptions;

use RuntimeException;

/**
 * A SQLite database couldn't be copied to or from a file.
 */
class SqliteCopyException extends RuntimeException {}
