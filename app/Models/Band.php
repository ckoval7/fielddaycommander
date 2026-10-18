<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Database\Factories\BandFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Band extends Model
{
    /** @use HasFactory<BandFactory> */
    use HasFactory;

    /**
     * Bands most stations work, which grids always list. Every other band is listed only once it has contacts.
     *
     * @var list<string>
     */
    public const COMMON_BAND_NAMES = ['160m', '80m', '40m', '20m', '15m', '10m', '6m', '2m', '70cm'];

    protected $fillable = [
        'name',
        'meters',
        'frequency_mhz',
        'is_hf',
        'is_vhf_uhf',
        'is_satellite',
        'allowed_fd',
        'allowed_wfd',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_hf' => 'boolean',
            'is_vhf_uhf' => 'boolean',
            'is_satellite' => 'boolean',
            'allowed_fd' => 'boolean',
            'allowed_wfd' => 'boolean',
        ];
    }

    /**
     * Whether the band is rarely used and should only be listed in grids once it has contacts.
     */
    public function isShownOnlyWithContacts(): bool
    {
        return $this->is_vhf_uhf || ($this->frequency_mhz !== null && (float) $this->frequency_mhz < 1.8);
    }

    /**
     * Keep the common bands, plus uncommon ones (LF, 1.25m, 33cm and up, Satellite) that have contacts in the event.
     */
    public function scopeWithUncommonBandsOnlyWhenWorked(Builder $query, int $eventConfigurationId): Builder
    {
        return $query->where(function (Builder $inner) use ($eventConfigurationId): void {
            $inner->whereIn('name', self::COMMON_BAND_NAMES)
                ->orWhereIn('id', Contact::query()
                    ->where('event_configuration_id', $eventConfigurationId)
                    ->notDuplicate()
                    ->whereNotNull('band_id')
                    ->select('band_id'));
        });
    }

    public function scopeAllowedForFieldDay(Builder $query): Builder
    {
        return $query->where('allowed_fd', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}
