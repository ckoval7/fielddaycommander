<?php

namespace App\Enums;

enum MessagePrecedence: string
{
    case Routine = 'routine';
    case Welfare = 'welfare';
    case Priority = 'priority';
    case Emergency = 'emergency';

    public function label(): string
    {
        return match ($this) {
            self::Routine => 'R - Routine',
            self::Welfare => 'W - Welfare',
            self::Priority => 'P - Priority',
            self::Emergency => 'EMERGENCY',
        };
    }

    public function abbreviation(): string
    {
        return match ($this) {
            self::Routine => 'R',
            self::Welfare => 'W',
            self::Priority => 'P',
            self::Emergency => 'EMERGENCY',
        };
    }
}
