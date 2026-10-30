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

    /**
     * Lowest frequency, in MHz, a free VHF station may operate on (6m and up).
     */
    public const VHF_STATION_MIN_MHZ = 50.0;

    /**
     * Whether a station may log on this band. A free VHF station operates
     * exclusively above 50 MHz, so it can't use LF or HF bands. Satellite
     * (no single frequency) stays allowed: its links are VHF and up.
     */
    public function isAllowedAtStation(Station $station): bool
    {
        if (! $station->is_vhf_only) {
            return true;
        }

        return $this->frequency_mhz === null || (float) $this->frequency_mhz >= self::VHF_STATION_MIN_MHZ;
    }

    /**
     * Why the station can't log on this band, or null when it can. Only the
     * free VHF station is limited: it operates exclusively above 50 MHz.
     */
    public function restrictionMessageAt(Station $station): ?string
    {
        if ($this->isAllowedAtStation($station)) {
            return null;
        }

        return "{$station->name} is marked VHF/UHF Only, so it can't log on {$this->name}. Choose 6m or a higher band.";
    }

    /**
     * Keep only the bands the station may log on (see isAllowedAtStation()).
     */
    public function scopeAllowedAtStation(Builder $query, ?Station $station): Builder
    {
        if (! $station?->is_vhf_only) {
            return $query;
        }

        return $query->where(fn (Builder $inner): Builder => $inner
            ->whereNull('frequency_mhz')
            ->orWhere('frequency_mhz', '>=', self::VHF_STATION_MIN_MHZ));
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
