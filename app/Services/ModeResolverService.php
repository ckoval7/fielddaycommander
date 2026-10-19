<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\Mode;

/**
 * Sorts a raw mode name into FD Commander's mode groups: CW, Phone or Digital.
 *
 * Neither ARRL Field Day nor Winter Field Day lists the modes it allows, so
 * this classifies rather than gatekeeps: CW is CW, a mode whose far end is
 * voice is Phone, and anything else is Digital. Event-specific exclusions
 * (such as the WFD 2027 WSJT ban) belong in the rule set, not here.
 */
class ModeResolverService
{
    /**
     * Mode names that carry voice: analog phone, plus digital voice, which
     * the rules score as Phone ("DIGITALVOICE" is the ADIF parent mode).
     *
     * @var array<int, string>
     */
    public const PHONE_MODES = [
        'SSB', 'USB', 'LSB', 'FM', 'AM', 'PM', 'NFM', 'WFM',
        'DIGITALVOICE', 'FREEDV', 'DSTAR', 'DMR', 'C4FM', 'M17',
    ];

    /**
     * Suggested sub-mode names for a mode group category (CW, Phone, Digital).
     *
     * @return array<int, string>
     */
    public static function submodeSuggestions(?string $category): array
    {
        return match ($category) {
            'CW' => ['CW'],
            'Phone' => ['USB', 'LSB', 'FM', 'AM', 'FREEDV', 'DSTAR', 'DMR', 'C4FM', 'M17'],
            'Digital' => ['RTTY', 'PSK31', 'SSTV', 'JTTY', 'FT8', 'FT4', 'JS8', 'JT65', 'JT9', 'MFSK', 'OLIVIA', 'VARA'],
            default => [],
        };
    }

    /**
     * Resolve a raw mode string to a FD Commander Mode ID.
     *
     * The sub-mode decides when it names CW or a voice mode; otherwise an
     * ADIF parent mode that does (e.g. DIGITALVOICE) decides; anything else
     * is Digital. A blank mode resolves to null: there is nothing to go on.
     */
    public function resolve(?string $modeName, ?string $parentModeName = null): ?int
    {
        $category = $this->category($modeName);

        if ($category === null) {
            return null;
        }

        if ($category === 'Digital') {
            $category = $this->category($parentModeName) ?? 'Digital';
        }

        return Mode::query()->where('name', $category)->value('id');
    }

    /**
     * The mode group a single mode name falls in, or null when it is blank.
     */
    private function category(?string $modeName): ?string
    {
        $upper = strtoupper(trim($modeName ?? ''));

        return match (true) {
            $upper === '' => null,
            $this->isCw($upper) => 'CW',
            in_array($upper, self::PHONE_MODES, true) => 'Phone',
            default => 'Digital',
        };
    }

    /**
     * CW, including the reverse and sideband variants rigs report over CAT
     * (CW-R, CWR, CW-U, CW-L).
     */
    private function isCw(string $upper): bool
    {
        return preg_match('/^CW(-?[RUL])?$/', $upper) === 1;
    }
}
