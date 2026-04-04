<?php

namespace App\Enums;

enum PowerSource: string
{
    case Generator = 'generator';
    case CommercialMains = 'commercial_mains';
    case Battery = 'battery';
    case Solar = 'solar';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Generator => 'Generator',
            self::CommercialMains => 'Commercial Mains',
            self::Battery => 'Battery',
            self::Solar => 'Solar',
            self::Other => 'Other',
        };
    }

    /**
     * Emergency power = everything except commercial mains.
     */
    public function isEmergencyPower(): bool
    {
        return $this !== self::CommercialMains;
    }

    /**
     * Natural power = battery, solar, or other (covers water, wind, methane, etc.).
     * NOT generator or commercial mains.
     */
    public function isNaturalPower(): bool
    {
        return match ($this) {
            self::Battery, self::Solar, self::Other => true,
            default => false,
        };
    }
}
