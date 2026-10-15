<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\QsoLogged;

/**
 * Shared logic for the WFD 2027 "three contacts on N different bands" objectives.
 *
 * "Log at least three QSOs on at least six different bands. You can use any
 * authorized band, not just HF." The twelve-band objective counts the same
 * bands, so both objectives differ only in the threshold.
 *
 * Unlike 2026, only contacts that earn credit count: the 2027 rules give no
 * credit to cross-band or relayed contacts (satellite contacts, and anything on
 * the Satellite pseudo-band) or to WSJT-mode contacts.
 */
abstract class BandCountStrategy extends AbstractBonusStrategy
{
    use CountsCreditableContacts;

    /** Minimum qualifying contacts a band needs before it counts. */
    protected const CONTACTS_PER_BAND = 3;

    /**
     * How many qualifying bands this objective requires.
     */
    abstract protected function requiredBands(): int;

    public function triggerType(): string
    {
        return 'derived';
    }

    public function subscribesTo(): array
    {
        return [QsoLogged::class];
    }

    public function reconcile(EventConfiguration $config): void
    {
        $achieved = $this->qualifyingBandCount($config) >= $this->requiredBands();

        $this->writeOrDelete($config, $this->bonusTypeFor($config), $achieved ? 1 : 0, 0);
    }

    /**
     * Distinct bands holding at least CONTACTS_PER_BAND creditable contacts.
     */
    protected function qualifyingBandCount(EventConfiguration $config): int
    {
        return $this->creditableContacts($config)
            ->whereNotNull('band_id')
            ->selectRaw('band_id')
            ->groupBy('band_id')
            ->havingRaw('COUNT(*) >= ?', [static::CONTACTS_PER_BAND])
            ->get()
            ->count();
    }
}
