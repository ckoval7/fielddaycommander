<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\DTOs;

use Illuminate\Support\Carbon;

readonly class ExternalContactDto
{
    /**
     * @param  array<string, mixed>  $advancedFields  Frequency, reports, grid, name and notes, keyed as stored on the contact
     * @param  array<string, string>|null  $adifFields  Every other ADIF tag of the record, to write back out on export
     */
    public function __construct(
        public string $callsign,
        public Carbon $timestamp,
        public string $source,
        public ?string $bandName = null,
        public ?string $modeName = null,
        public ?string $operatorCallsign = null,
        public ?string $stationIdentifier = null,
        public ?int $frequencyHz = null,
        public ?string $sentReport = null,
        public ?string $sentExchange = null,
        public ?string $receivedReport = null,
        public ?string $receivedExchange = null,
        public ?string $exchangeClass = null,
        public ?string $sectionCode = null,
        public ?string $externalId = null,
        public bool $isDelete = false,
        public bool $isReplace = false,
        public ?string $oldCallsign = null,
        public ?Carbon $oldTimestamp = null,
        public ?string $parentModeName = null,
        public array $advancedFields = [],
        public ?array $adifFields = null,
    ) {}
}
