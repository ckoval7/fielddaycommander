<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventType extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'setup_offset_hours',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * When setup may begin for an event of this type starting at $startTime.
     *
     * ARRL Field Day (rule 3.3) opens setup at 0000 UTC on the Friday before
     * the event, which is 42 hours before the usual 1800 UTC Saturday start.
     * It is worked out from the date so an event that starts at another hour
     * still opens setup at the start of that Friday. Other types open setup
     * a fixed number of hours before the start, and none when no offset is set.
     */
    public function setupAllowedFrom(Carbon $startTime): ?Carbon
    {
        if ($this->code === 'FD') {
            return $startTime->copy()->utc()->previous(Carbon::FRIDAY);
        }

        if ($this->setup_offset_hours === null) {
            return null;
        }

        return Event::calculateSetupAllowedFrom($startTime, $this->setup_offset_hours);
    }
}
