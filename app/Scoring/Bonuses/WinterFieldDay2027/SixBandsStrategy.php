<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

/**
 * WFD 2027 objective "six_bands": three contacts on at least six bands. OM x6.
 */
class SixBandsStrategy extends BandCountStrategy
{
    public function code(): string
    {
        return 'six_bands';
    }

    protected function requiredBands(): int
    {
        return 6;
    }
}
