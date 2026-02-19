<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperatingSession extends Model
{
    /** @use HasFactory<\Database\Factories\OperatingSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'station_id',
        'operator_user_id',
        'band_id',
        'mode_id',
        'start_time',
        'end_time',
        'power_watts',
        'qso_count',
        'is_transcription',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'power_watts' => 'integer',
            'is_transcription' => 'boolean',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    public function band(): BelongsTo
    {
        return $this->belongsTo(Band::class);
    }

    public function mode(): BelongsTo
    {
        return $this->belongsTo(Mode::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('end_time');
    }

    public function scopeForStation(Builder $query, int $stationId): Builder
    {
        return $query->where('station_id', $stationId);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('operator_user_id', $userId);
    }

    public function scopeTranscription(Builder $query): Builder
    {
        return $query->where('is_transcription', true);
    }

    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->end_time === null,
        );
    }
}
