<?php

namespace App\Enums;

enum ChecklistType: string
{
    case SafetyOfficer = 'safety_officer';
    case SiteResponsibilities = 'site_responsibilities';

    /**
     * Get the human-readable label for the checklist type.
     */
    public function label(): string
    {
        return match ($this) {
            self::SafetyOfficer => 'Safety Officer',
            self::SiteResponsibilities => 'Site Responsibilities',
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
        };
    }
}
