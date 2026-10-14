<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who signed into a demo sandbox, with the name and call sign they
 * supplied. Fields a visitor left blank stay null rather than holding the
 * identity generated for them.
 */
class DemoParticipant extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'demo_session_id',
        'role',
        'via_invite',
        'first_name',
        'last_name',
        'call_sign',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'via_invite' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function demoSession(): BelongsTo
    {
        return $this->belongsTo(DemoSession::class);
    }

    /**
     * Record a visitor signing in, keeping only the identity fields they filled in.
     *
     * @param  array{role: string, first_name?: string|null, last_name?: string|null, call_sign?: string|null}  $validated
     */
    public static function record(DemoSession $demoSession, array $validated, bool $viaInvite): self
    {
        return self::create([
            'demo_session_id' => $demoSession->id,
            'role' => $validated['role'],
            'via_invite' => $viaInvite,
            'first_name' => filled($validated['first_name'] ?? null) ? $validated['first_name'] : null,
            'last_name' => filled($validated['last_name'] ?? null) ? $validated['last_name'] : null,
            'call_sign' => filled($validated['call_sign'] ?? null) ? strtoupper($validated['call_sign']) : null,
        ]);
    }

    public function displayName(): ?string
    {
        $name = trim($this->first_name.' '.$this->last_name);

        return $name === '' ? null : $name;
    }

    protected static function booted(): void
    {
        static::creating(function (self $participant) {
            $participant->created_at ??= now();
        });
    }

    /**
     * Analytics always live on the main database, never the per-visitor
     * demo connection that DemoMiddleware swaps in.
     */
    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('demo.analytics_connection');
    }
}
