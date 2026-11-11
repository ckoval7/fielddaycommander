<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Exceptions;

use RuntimeException;

/**
 * A backup could not be made or restored. The message is shown to the person
 * running the backup, so it says what went wrong in plain words.
 */
class SiteBackupException extends RuntimeException {}
