<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an uploaded image can't be decoded to remove its metadata.
 */
class ImageProcessingException extends RuntimeException {}
