<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Rules;

use App\Models\BonusType;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Station;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\Dto\Nomenclature;
use App\Scoring\Dto\ScoreBreakdown;

/**
 * Null ruleset for unscored events: public service, nets and drills.
 *
 * These events are not contests, so contacts earn no points and there are no
 * bonuses. The ruleset exists so code that asks an event for its rules keeps
 * working; the UI hides scoring surfaces through {@see self::isScored()}.
 */
class PublicService implements RuleSet
{
    public function id(): string
    {
        return 'PS-1';
    }

    public function version(): string
    {
        return '1';
    }

    public function eventTypeCode(): string
    {
        return 'PS';
    }

    public function isScored(): bool
    {
        return false;
    }

    public function pointsForContact(Mode $mode, Station $station): int
    {
        return 0;
    }

    public function score(EventConfiguration $config): ScoreBreakdown
    {
        return new ScoreBreakdown(total: 0, lines: []);
    }

    public function nomenclature(): Nomenclature
    {
        return new Nomenclature(
            awardSingular: 'Bonus',
            awardPlural: 'Bonuses',
            awardSectionTitle: 'Bonuses',
            claimsTitle: 'Bonus Claims',
            awardValueLabel: 'Points',
            rulebookName: '',
            bulletinName: 'Bulletin',
            bulletinSources: [],
            bulletinCapturedLabel: 'Bulletin Copied',
            stationSingular: 'Position',
            stationPlural: 'Positions',
        );
    }

    public function cabrilloContestName(): string
    {
        return '';
    }

    public function logFilenameSlug(): string
    {
        return 'public-service';
    }

    public function bonus(string $code): ?BonusType
    {
        return null;
    }

    public function strategies(): array
    {
        return [];
    }

    public function strategiesFor(string $eventClass): array
    {
        return [];
    }

    public function bonusRuleReference(string $code): ?array
    {
        return null;
    }
}
