<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use App\Enums\PowerSource;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'event_configuration_id',
        'operating_session_id',
        'logger_user_id',
        'band_id',
        'mode_id',
        'submode',
        'frequency_hz',
        'qso_time',
        'callsign',
        'section_id',
        'exchange_class',
        'power_watts',
        'power_source',
        'rst_sent',
        'rst_received',
        'grid_square',
        'contact_name',
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
        'is_imported',
        'external_id',
        'external_source',
        'duplicate_of_contact_id',
        'notes',
        'adif_fields',
    ];

    protected function casts(): array
    {
        return [
            'qso_time' => 'datetime',
            'frequency_hz' => 'integer',
            'adif_fields' => 'array',
            'is_gota_contact' => 'boolean',
            'power_source' => PowerSource::class,
            'is_natural_power' => 'boolean',
            'is_satellite' => 'boolean',
            'is_duplicate' => 'boolean',
            'is_transcribed' => 'boolean',
            'is_imported' => 'boolean',
        ];
    }

    /**
     * Flag satellite contacts from their band and station on every logging path.
     *
     * A contact counts as a satellite QSO when it is on the Satellite band or
     * logged in a session on a Satellite Station. Browser logging, transcription,
     * ADIF import and external loggers all build a Contact and save it, so the
     * flag is derived here rather than in each path. This overrides save()
     * rather than listening for the saving event because ADIF import and the
     * duplicate checker save quietly, which skips model events. An explicitly
     * set flag is kept, and the flag is re-derived when the band or session
     * changes.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if (! $this->exists || $this->isDirty(['band_id', 'operating_session_id'])) {
            $explicitlyFlagged = $this->is_satellite && (! $this->exists || $this->isDirty('is_satellite'));

            $this->is_satellite = $explicitlyFlagged || $this->isOnSatelliteBandOrStation();
        }

        return parent::save($options);
    }

    /**
     * Determine whether the contact's band or its session's station is a satellite one.
     */
    public function isOnSatelliteBandOrStation(): bool
    {
        if ($this->band_id !== null && Band::whereKey($this->band_id)->where('is_satellite', true)->exists()) {
            return true;
        }

        return $this->operating_session_id !== null
            && OperatingSession::whereKey($this->operating_session_id)
                ->whereHas('station', fn (Builder $query) => $query->withTrashed()->where('is_satellite', true))
                ->exists();
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
     * Live contacts that earn no points until someone fixes them: missing a
     * band or mode (typically from an external logger), or logged outside
     * their event's start/end window. Cabrillo export skips both kinds.
     *
     * A contact with no QSO time, or whose event has no window set, counts as
     * in the window, matching DuplicateCheckService.
     *
     * Pass the event when the query covers one event's contacts: its window
     * then ends at Event::operatingEndsAt(), which can be 24 hours after the
     * first contact. Without it the window is compared in SQL against each
     * contact's event end_time.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeNeedsAttention(Builder $query, ?Event $event = null): Builder
    {
        $operatingEndsAt = $event?->operatingEndsAt();

        return $query->withoutTrashed()->where(fn (Builder $problem) => $problem
            ->whereNull('band_id')
            ->orWhereNull('mode_id')
            ->orWhere(fn (Builder $outOfWindow) => $event !== null
                ? $outOfWindow
                    ->whereNotNull('qso_time')
                    ->when(
                        $event->start_time === null || $operatingEndsAt === null,
                        fn (Builder $none) => $none->whereRaw('1 = 0'),
                        fn (Builder $outside) => $outside->where(fn (Builder $either) => $either
                            ->where('qso_time', '<', $event->start_time)
                            ->orWhere('qso_time', '>', $operatingEndsAt)),
                    )
                : $outOfWindow
                    ->whereNotNull('qso_time')
                    ->whereHas('eventConfiguration.event', fn (Builder $events) => $events
                        ->whereNotNull('events.start_time')
                        ->whereNotNull('events.end_time')
                        ->where(fn (Builder $outside) => $outside
                            ->whereColumn('events.start_time', '>', 'contacts.qso_time')
                            ->orWhereColumn('events.end_time', '<', 'contacts.qso_time')))));
    }

    /**
     * Why this contact needs attention, empty when it doesn't. Pass the
     * contact's event when it is already loaded to avoid a lookup per contact.
     *
     * @return list<string>
     */
    public function attentionReasons(?Event $event = null): array
    {
        if ($this->trashed()) {
            return [];
        }

        $reasons = [];

        if ($this->band_id === null) {
            $reasons[] = 'Missing band';
        }

        if ($this->mode_id === null) {
            $reasons[] = 'Missing mode';
        }

        $event ??= $this->eventConfiguration?->event;

        if ($this->qso_time !== null && $event !== null && ! $event->isWithinOperatingPeriod($this->qso_time)) {
            $reasons[] = 'Outside event window';
        }

        return $reasons;
    }

    /**
     * Normalize callsign to uppercase.
     */
    protected function setCallsignAttribute(?string $value): void
    {
        $this->attributes['callsign'] = $value ? mb_strtoupper($value) : null;
    }

    /**
     * Normalize exchange class to uppercase.
     */
    protected function setExchangeClassAttribute(?string $value): void
    {
        $this->attributes['exchange_class'] = $value ? mb_strtoupper(trim($value)) : null;
    }

    /**
     * Normalize the sub-mode (e.g. FT8, SSTV, USB) to uppercase; blank becomes null.
     */
    protected function setSubmodeAttribute(?string $value): void
    {
        $value = $value !== null ? mb_strtoupper(trim($value)) : '';
        $this->attributes['submode'] = $value !== '' ? mb_substr($value, 0, 20) : null;
    }

    /**
     * Normalize GOTA operator callsign to uppercase.
     */
    protected function setGotaOperatorCallsignAttribute(?string $value): void
    {
        $this->attributes['gota_operator_callsign'] = $value ? mb_strtoupper($value) : null;
    }
}
