<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

/**
 * Pre-written local alerts operators can broadcast with one click.
 */
enum CannedWeatherAlert: string
{
    case LightningWithinFifteenMiles = 'lightning_15mi';
    case LightningWithinTenMiles = 'lightning_10mi';

    public function label(): string
    {
        return match ($this) {
            self::LightningWithinFifteenMiles => 'Lightning < 15 mi',
            self::LightningWithinTenMiles => 'Lightning < 10 mi',
        };
    }

    public function headline(): string
    {
        return match ($this) {
            self::LightningWithinFifteenMiles => 'Lightning within 15 miles. Disconnect radios from power and antennas.',
            self::LightningWithinTenMiles => 'Lightning within 10 miles. Hands off all wired equipment and seek shelter immediately.',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LightningWithinFifteenMiles => 'Stop operating. Disconnect feedlines from radios and unplug radios from power. '
                .'Do not touch coax, antennas, masts, or guy lines once disconnected. '
                .'Be ready to move to shelter. The next step is a full stand-down.',
            self::LightningWithinTenMiles => 'Do not touch radios, feedlines, power cords, generators, or anything connected to an antenna. '
                .'Go to a hard-topped vehicle or enclosed building now. Tents and canopies are not shelter. '
                .'Remain sheltered until 30 minutes after the last lightning or thunder within 10 miles.',
        };
    }

    public function buttonClass(): string
    {
        return match ($this) {
            self::LightningWithinFifteenMiles => 'btn-warning',
            self::LightningWithinTenMiles => 'btn-error',
        };
    }
}
