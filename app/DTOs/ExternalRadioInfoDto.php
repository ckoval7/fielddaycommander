<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\DTOs;

readonly class ExternalRadioInfoDto
{
    public function __construct(
        public string $stationIdentifier,
        public string $source,
        public ?string $operatorCallsign = null,
        public ?int $frequencyHz = null,
        public ?string $modeName = null,
        public bool $isTransmitting = false,
    ) {}
}
