<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafetyChecklistEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'safety_checklist_item_id',
        'is_completed',
        'completed_by_user_id',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(SafetyChecklistItem::class, 'safety_checklist_item_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
