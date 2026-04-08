<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoEvent extends Model
{
    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = [
        'demo_session_id',
        'type',
        'name',
        'route_name',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function demoSession(): BelongsTo
    {
        return $this->belongsTo(DemoSession::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->created_at ??= now();
        });
    }
}
