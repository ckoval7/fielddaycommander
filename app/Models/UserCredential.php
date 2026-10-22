<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use App\Enums\Credential;
use Database\Factories\UserCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credential a user holds, such as IS-100 or AUXCOMM, with its level and
 * expiry date when it has them.
 */
class UserCredential extends Model
{
    /** @use HasFactory<UserCredentialFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'credential',
        'level',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'credential' => Credential::class,
            'expires_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(appNow()->startOfDay());
    }

    /**
     * The credential as an ICS form shows it, e.g. "IS-100" or "ARES L2".
     */
    public function shortLabel(): string
    {
        $label = $this->credential->label();

        if ($this->level !== null && preg_match('/(\d+)$/', $this->level, $matches)) {
            return "{$label} L{$matches[1]}";
        }

        return $label;
    }
}
