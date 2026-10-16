<?php

namespace App\Enums;

enum StationUnavailableReason: string
{
    case EquipmentFailure = 'equipment_failure';
    case AntennaProblem = 'antenna_problem';
    case Maintenance = 'maintenance';
    case Other = 'other';

    /**
     * Get the human-readable label for the reason.
     */
    public function label(): string
    {
        return match ($this) {
            self::EquipmentFailure => 'Equipment Failure',
            self::AntennaProblem => 'Antenna Problem',
            self::Maintenance => 'Maintenance / Setup',
            self::Other => 'Other',
        };
    }

    /**
     * Get the icon used when displaying this reason.
     */
    public function icon(): string
    {
        return match ($this) {
            self::EquipmentFailure => 'phosphor-wrench',
            self::AntennaProblem => 'phosphor-cell-tower',
            self::Maintenance => 'phosphor-toolbox',
            self::Other => 'phosphor-warning-circle',
        };
    }

    /**
     * Get the reasons as select options.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
            self::cases(),
        );
    }
}
