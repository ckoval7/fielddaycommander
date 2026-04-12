<?php

namespace App\Services;

use App\Models\Mode;

class ModeResolverService
{
    /**
     * ADIF mode names that map to FD Commander's "Phone" mode.
     *
     * @var array<string>
     */
    private const PHONE_MODES = ['SSB', 'USB', 'LSB', 'FM', 'AM', 'PM'];

    /**
     * ADIF mode names that map to FD Commander's "Digital" mode.
     *
     * @var array<string>
     */
    private const DIGITAL_MODES = [
        'RTTY', 'PSK', 'PSK31', 'PSK63', 'FT8', 'FT4', 'JS8', 'JT65',
        'JT9', 'MFSK', 'OLIVIA', 'CONTESTI', 'DOMINO', 'FSK', 'HELL',
        'ROS', 'THROB', 'SSTV', 'FAX', 'PKT', 'DSTAR', 'DMR', 'C4FM',
        'FREEDV', 'ARDOP', 'WINMOR', 'VARA',
    ];

    /**
     * Resolve a raw mode string to a FD Commander Mode ID.
     */
    public function resolve(?string $modeName): ?int
    {
        if ($modeName === null) {
            return null;
        }

        $modes = Mode::query()->pluck('id', 'name')->toArray();
        $upper = strtoupper($modeName);

        $category = match (true) {
            $upper === 'CW' => 'CW',
            in_array($upper, self::PHONE_MODES, true) => 'Phone',
            in_array($upper, self::DIGITAL_MODES, true) => 'Digital',
            default => null,
        };

        return $category !== null ? ($modes[$category] ?? null) : null;
    }
}
