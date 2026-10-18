<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Bonuses\WinterFieldDay2026;

/**
 * WFD 2026 objective "twelve_bands": three contacts on at least twelve bands. OM x6.
 *
 * The six bands counted for the six-band objective count toward this one too,
 * so both objectives can be held at once.
 */
class TwelveBandsStrategy extends BandCountStrategy
{
    public function code(): string
    {
        return 'twelve_bands';
    }

    protected function requiredBands(): int
    {
        return 12;
    }
}
