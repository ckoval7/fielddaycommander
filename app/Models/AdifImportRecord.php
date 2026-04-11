<?php

namespace App\Models;

use App\Enums\AdifRecordStatus;
use Database\Factories\AdifImportRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdifImportRecord extends Model
{
    /** @use HasFactory<AdifImportRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'adif_import_id',
        'raw_data',
        'callsign',
        'qso_time',
        'band_name',
        'mode_name',
        'section_code',
        'exchange_class',
        'station_identifier',
        'operator_callsign',
        'band_id',
        'mode_id',
        'section_id',
        'station_id',
        'operator_user_id',
        'matched_contact_id',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'qso_time' => 'datetime',
            'status' => AdifRecordStatus::class,
        ];
    }

    public function adifImport(): BelongsTo
    {
        return $this->belongsTo(AdifImport::class);
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

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    public function matchedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'matched_contact_id');
    }
}
