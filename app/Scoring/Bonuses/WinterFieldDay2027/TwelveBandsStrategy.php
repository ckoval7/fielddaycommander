<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

/**
 * WFD 2027 objective "twelve_bands": three contacts on at least twelve bands. OM x6.
 *
 * "The six bands from the previous objective count toward this one", so both
 * objectives can be held at once.
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
