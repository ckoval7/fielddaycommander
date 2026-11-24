<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use App\Enums\InterestAvailability;
use App\Enums\LicenseClass;
use Database\Factories\EventInterestFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone saying they'd like to help at an event, collected before shift
 * sign-ups open. One response per email address per event.
 */
class EventInterest extends Model
{
    /** @use HasFactory<EventInterestFactory> */
    use HasFactory;

    protected $fillable = [
        'event_configuration_id',
        'user_id',
        'name',
        'callsign',
        'email',
        'license_class',
        'availability',
        'notes',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'license_class' => LicenseClass::class,
            'availability' => AsEnumCollection::of(InterestAvailability::class),
        ];
    }

    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
