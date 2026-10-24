<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use App\Enums\ChecklistAnswerType;
use Database\Factories\ChecklistItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChecklistItem extends Model
{
    /** @use HasFactory<ChecklistItemFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'checklist_id',
        'label',
        'help_text',
        'answer_type',
        'is_required',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'answer_type' => ChecklistAnswerType::class,
            'is_required' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Checklist, $this>
     */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    /**
     * @return HasMany<ChecklistAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(ChecklistAnswer::class);
    }
}
