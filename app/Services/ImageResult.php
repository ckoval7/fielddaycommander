<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

readonly class ImageResult
{
    public function __construct(
        public string $path,
        public ?string $thumbnailPath,
        public string $hash,
        public string $mimeType,
        public int $size,
    ) {}
}
