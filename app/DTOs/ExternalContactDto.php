<?php

namespace App\DTOs;

use Illuminate\Support\Carbon;

readonly class ExternalContactDto
{
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
    ) {}
}
