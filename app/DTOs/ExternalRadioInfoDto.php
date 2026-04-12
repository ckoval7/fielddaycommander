<?php

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
