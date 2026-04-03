<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    /** @use HasFactory<\Database\Factories\ContactFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'event_configuration_id',
        'operating_session_id',
        'logger_user_id',
        'band_id',
        'mode_id',
        'qso_time',
        'callsign',
        'section_id',
        'received_exchange',
        'power_watts',
        'is_gota_contact',
        'gota_operator_first_name',
        'gota_operator_last_name',
        'gota_operator_callsign',
        'gota_coach_user_id',
        'gota_operator_user_id',
        'is_natural_power',
        'is_satellite',
        'satellite_name',
        'points',
        'is_duplicate',
        'is_transcribed',
        'duplicate_of_contact_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'qso_time' => 'datetime',
            'is_gota_contact' => 'boolean',
            'is_natural_power' => 'boolean',
            'is_satellite' => 'boolean',
            'is_duplicate' => 'boolean',
            'is_transcribed' => 'boolean',
        ];
    }

    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }

    public function operatingSession(): BelongsTo
    {
        return $this->belongsTo(OperatingSession::class);
    }

    public function logger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logger_user_id');
    }

    public function band(): BelongsTo
    {
        return $this->belongsTo(Band::class);
    }

    public function mode(): BelongsTo
    {
        return $this->belongsTo(Mode::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'duplicate_of_contact_id');
    }

    public function gotaOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gota_operator_user_id');
    }

    public function gotaCoach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gota_coach_user_id');
    }

    // Scopes
    public function scopeForEvent($query, $eventConfigId)
    {
        return $query->where('event_configuration_id', $eventConfigId);
    }

    public function scopeNotDuplicate($query)
    {
        return $query->where('is_duplicate', false);
    }

    public function scopeDuplicatesOnly($query)
    {
        return $query->where('is_duplicate', true);
    }

    public function scopeChronological($query)
    {
        return $query->orderBy('qso_time', 'desc');
    }

    public function scopeTranscribed($query)
    {
        return $query->where('is_transcribed', true);
    }

    /**
     * Normalize callsign to uppercase.
     */
    protected function setCallsignAttribute(?string $value): void
    {
        $this->attributes['callsign'] = $value ? mb_strtoupper($value) : null;
    }

    /**
     * Normalize received exchange to uppercase.
     */
    protected function setReceivedExchangeAttribute(?string $value): void
    {
        $this->attributes['received_exchange'] = $value ? mb_strtoupper($value) : null;
    }

    /**
     * Normalize GOTA operator callsign to uppercase.
     */
    protected function setGotaOperatorCallsignAttribute(?string $value): void
    {
        $this->attributes['gota_operator_callsign'] = $value ? mb_strtoupper($value) : null;
    }
}
