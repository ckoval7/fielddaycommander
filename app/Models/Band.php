<?php

namespace App\Models;

use Database\Factories\BandFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Band extends Model
{
    /** @use HasFactory<BandFactory> */
    use HasFactory;

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
     * Keep the standard 160m-23cm bands and Satellite, plus LF/microwave bands that have contacts in the event.
     */
    public function scopeWithExtremeBandsOnlyWhenWorked(Builder $query, int $eventConfigurationId): Builder
    {
        return $query->where(function (Builder $inner) use ($eventConfigurationId): void {
            $inner->whereNull('frequency_mhz')
                ->orWhereBetween('frequency_mhz', [1.8, 1300])
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
