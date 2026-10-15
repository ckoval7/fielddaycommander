<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Scoring\Bonuses\AbstractBonusStrategy;
use App\Scoring\DomainEvents\QsoLogged;

/**
 * WFD 2027 objective "multiple_modes". OM x2.
 *
 * "Increase your versatility by completing at least one QSO on multiple modes
 * during the event, such as Phone and CW, CW and Digital, or Phone and
 * Digital. Making a QSO on all three modes does not increase this OM." Two
 * distinct mode groups is the threshold, as in 2026, but only contacts that
 * earn credit count — an FT8 contact does not make Digital a mode "used".
 */
class MultipleModesStrategy extends AbstractBonusStrategy
{
    use CountsCreditableContacts;

    /** Distinct mode groups (CW / Phone / Digital) needed to achieve this. */
    protected const REQUIRED_MODE_GROUPS = 2;

    public function code(): string
    {
        return 'multiple_modes';
    }

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
        $modeGroups = Mode::query()
            ->whereIn('id', $this->creditableContacts($config)->whereNotNull('mode_id')->select('mode_id'))
            ->whereNotNull('category')
            ->distinct()
            ->count('category');

        $achieved = $modeGroups >= static::REQUIRED_MODE_GROUPS;

        $this->writeOrDelete($config, $this->bonusTypeFor($config), $achieved ? 1 : 0, 0);
    }
}
