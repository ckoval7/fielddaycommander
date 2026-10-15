<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\QsoLogged;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shared logic for the WFD 2027 "three contacts on N different bands" objectives.
 *
 * "Log at least three QSOs on at least six different bands. You can use any
 * authorized band, not just HF." The twelve-band objective counts the same
 * bands, so both objectives differ only in the threshold.
 *
 * Unlike 2026, satellite contacts do not count: the 2027 rules give no credit
 * to cross-band or relayed contacts, so a satellite QSO is not a WFD QSO. Both
 * contacts flagged as satellite and contacts on the Satellite pseudo-band are
 * left out of the tally.
 */
abstract class BandCountStrategy extends AbstractBonusStrategy
{
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
     * Distinct bands holding at least CONTACTS_PER_BAND countable contacts.
     */
    protected function qualifyingBandCount(EventConfiguration $config): int
    {
        return $this->countableContacts($config)
            ->whereNotNull('band_id')
            ->selectRaw('band_id')
            ->groupBy('band_id')
            ->havingRaw('COUNT(*) >= ?', [static::CONTACTS_PER_BAND])
            ->get()
            ->count();
    }

    /**
     * Contacts that may count toward a band: non-duplicate and not via satellite.
     *
     * @return HasMany<Contact, EventConfiguration>
     */
    protected function countableContacts(EventConfiguration $config): HasMany
    {
        return $config->contacts()
            ->where('is_duplicate', false)
            ->where('is_satellite', false)
            ->whereNotIn('band_id', Band::query()->where('is_satellite', true)->select('id'));
    }
}
