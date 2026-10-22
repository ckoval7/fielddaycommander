<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Database\Factories\RollCallStationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A station a roll call expects to answer. The name and operator callsign
 * are a snapshot from when the roll call started, so an answer under either
 * accounts for it.
 */
class RollCallStation extends Model
{
    /** @use HasFactory<RollCallStationFactory> */
    use HasFactory;

    protected $fillable = [
        'roll_call_id',
        'station_id',
        'name',
        'callsign',
        'accounted_at',
        'net_log_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'accounted_at' => 'datetime',
        ];
    }

    public function rollCall(): BelongsTo
    {
        return $this->belongsTo(RollCall::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * The entry logged when net control marked this station by hand, which
     * undoing the mark removes again.
     */
    public function netLogEntry(): BelongsTo
    {
        return $this->belongsTo(NetLogEntry::class);
    }

    public function isAccounted(): bool
    {
        return $this->accounted_at !== null;
    }

    /**
     * Whether a station named in the log is this one, by name or callsign.
     */
    public function answersTo(?string $station): bool
    {
        $station = mb_strtolower(trim((string) $station));

        return $station !== ''
            && ($station === mb_strtolower($this->name) || ($this->callsign !== null && $station === mb_strtolower($this->callsign)));
    }
}
