<?php

namespace App\Models;

use App\Enums\AdifImportStatus;
use Database\Factories\AdifImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdifImport extends Model
{
    /** @use HasFactory<AdifImportFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'event_configuration_id',
        'user_id',
        'filename',
        'status',
        'total_records',
        'mapped_records',
        'imported_records',
        'skipped_records',
        'merged_records',
        'field_mapping',
        'station_mapping',
        'operator_mapping',
        'inconsistencies_resolved',
    ];

    protected function casts(): array
    {
        return [
            'status' => AdifImportStatus::class,
            'field_mapping' => 'array',
            'station_mapping' => 'array',
            'operator_mapping' => 'array',
            'inconsistencies_resolved' => 'array',
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

    public function records(): HasMany
    {
        return $this->hasMany(AdifImportRecord::class);
    }
}
