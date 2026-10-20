<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

enum ChecklistType: string
{
    case SafetyOfficer = 'safety_officer';
    case SiteResponsibilities = 'site_responsibilities';
    case Briefing = 'briefing';

    /**
     * Get the human-readable label for the checklist type.
     */
    public function label(): string
    {
        return match ($this) {
            self::SafetyOfficer => 'Safety Officer',
            self::SiteResponsibilities => 'Site Responsibilities',
            self::Briefing => 'Volunteer Briefing',
        };
    }

    /**
     * Get the bonus points awarded for completing this checklist type.
     */
    public function bonusPoints(): int
    {
        return match ($this) {
            self::SafetyOfficer => 100,
            self::SiteResponsibilities => 50,
            self::Briefing => 0,
        };
    }

    /**
     * The bonus this checklist earns when complete, or null when it earns none.
     */
    public function bonusCode(): ?string
    {
        return $this === self::Briefing ? null : $this->value;
    }
}
