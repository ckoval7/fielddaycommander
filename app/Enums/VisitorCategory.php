<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

enum VisitorCategory: string
{
    case GeneralPublic = 'general_public';
    case HamClub = 'ham_club';
    case Youth = 'youth';
    case AresRaces = 'ares_races';
    case Media = 'media';
    case Agency = 'agency';
    case ArrlOfficial = 'arrl_official';
    case ElectedOfficial = 'elected_official';

    public function label(): string
    {
        return match ($this) {
            self::GeneralPublic => 'General Public',
            self::HamClub => 'Amateur Radio Club Member',
            self::Youth => 'Youth',
            self::AresRaces => 'ARES/RACES Volunteer',
            self::Media => 'Media Representative',
            self::Agency => 'Agency Representative (e.g., Red Cross)',
            self::ArrlOfficial => 'ARRL Official',
            self::ElectedOfficial => 'Elected Official',
        };
    }

    /**
     * Whether a verified visit in this category earns a guestbook bonus.
     *
     * Media visits are tracked but the Media Publicity bonus is claimed manually.
     */
    public function isBonusEligible(): bool
    {
        return in_array($this, self::bonusEligible(), true);
    }

    /**
     * @return list<self>
     */
    public static function bonusEligible(): array
    {
        return [self::ElectedOfficial, self::Agency];
    }

    /**
     * The rulebook bonus code a verified visit in this category earns, if any.
     */
    public function bonusCode(): ?string
    {
        return match ($this) {
            self::ElectedOfficial => 'elected_official_visit',
            self::Agency => 'agency_visit',
            default => null,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $category) => ['value' => $category->value, 'label' => $category->label()],
            self::cases(),
        );
    }
}
