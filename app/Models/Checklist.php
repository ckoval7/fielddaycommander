<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use App\Enums\ChecklistCompletionRule;
use App\Enums\ChecklistScope;
use Database\Factories\ChecklistFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A checklist an event runs: its items, who may complete it, and how often
 * it runs (once for the event, per station or per person).
 */
class Checklist extends Model
{
    /** @use HasFactory<ChecklistFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_configuration_id',
        'name',
        'description',
        'scope',
        'completion_rule',
        'bonus_code',
        'template_key',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'scope' => ChecklistScope::class,
            'completion_rule' => ChecklistCompletionRule::class,
        ];
    }

    /**
     * @return BelongsTo<EventConfiguration, $this>
     */
    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }

    /**
     * @return HasMany<ChecklistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<ChecklistRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ChecklistRun::class);
    }

    /**
     * The shift roles whose volunteers may complete this checklist. None
     * means anyone with a shift at the event.
     *
     * @return BelongsToMany<ShiftRole, $this>
     */
    public function shiftRoles(): BelongsToMany
    {
        return $this->belongsToMany(ShiftRole::class);
    }

    /**
     * @param  Builder<Checklist>  $query
     * @return Builder<Checklist>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Whether completing this checklist earns a bonus.
     */
    public function earnsBonus(): bool
    {
        return $this->bonus_code !== null && $this->scope === ChecklistScope::Event;
    }
}
