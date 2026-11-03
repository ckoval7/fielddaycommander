<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services\N3fjp;

use RuntimeException;

/**
 * A connection sent something that cannot be N3FJP network traffic.
 */
class N3fjpProtocolException extends RuntimeException {}
