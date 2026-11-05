<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\AuditLog;
use App\Models\EventConfiguration;
use App\Models\Relay;
use Illuminate\Support\Str;

/**
 * Issues one-time enrollment codes and trades them for relay tokens.
 */
class RelayEnrollmentService
{
    public const CODE_LIFETIME_MINUTES = 15;

    /**
     * Letters and digits that can't be misread for one another.
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 12;

    /**
     * Create a relay waiting to enroll. The code is only available here,
     * because only its hash is stored.
     *
     * @return array{relay: Relay, code: string}
     */
    public function issue(EventConfiguration $config, string $name, ?int $createdByUserId = null): array
    {
        $code = $this->generateCode();

        $relay = Relay::create([
            'event_configuration_id' => $config->id,
            'name' => $name,
            'enrollment_code_hash' => Relay::hashSecret($this->normalizeCode($code)),
            'enrollment_expires_at' => now()->addMinutes(self::CODE_LIFETIME_MINUTES),
            'created_by_user_id' => $createdByUserId,
        ]);

        AuditLog::log('relay.code_issued', userId: $createdByUserId, auditable: $relay, newValues: [
            'name' => $name,
            'event_configuration_id' => $config->id,
        ]);

        return ['relay' => $relay, 'code' => $code];
    }

    /**
     * Trade an enrollment code for a token. A code works once, and only until
     * it expires. The token is only available here, because only its hash is
     * stored.
     *
     * @return array{relay: Relay, token: string}|null Null when the code can't be used
     */
    public function redeem(string $code, ?string $relayVersion = null): ?array
    {
        $codeHash = Relay::hashSecret($this->normalizeCode($code));

        $relay = Relay::active()
            ->where('enrollment_code_hash', $codeHash)
            ->where('enrollment_expires_at', '>', now())
            ->first();

        if ($relay === null) {
            AuditLog::log('relay.enrollment_failed');

            return null;
        }

        $token = 'fdca_'.Str::random(64);

        // Conditional on the code still being there, so two requests racing
        // with the same code can't both get a token.
        $claimed = Relay::query()
            ->whereKey($relay->id)
            ->where('enrollment_code_hash', $codeHash)
            ->update([
                'enrollment_code_hash' => null,
                'enrollment_expires_at' => null,
                'token_hash' => Relay::hashSecret($token),
                'relay_version' => $relayVersion,
                'enrolled_at' => now(),
                'last_seen_at' => now(),
            ]);

        if ($claimed !== 1) {
            AuditLog::log('relay.enrollment_failed', auditable: $relay);

            return null;
        }

        AuditLog::log(
            'relay.enrolled',
            userId: $relay->created_by_user_id,
            auditable: $relay,
            newValues: [
                'name' => $relay->name,
                'event_configuration_id' => $relay->event_configuration_id,
                'relay_version' => $relayVersion,
            ],
        );

        return ['relay' => $relay->refresh(), 'token' => $token];
    }

    /**
     * Stop a relay's token or unused code from working.
     */
    public function revoke(Relay $relay, ?int $revokedByUserId = null): void
    {
        $relay->update([
            'revoked_at' => now(),
            'enrollment_code_hash' => null,
            'enrollment_expires_at' => null,
        ]);

        AuditLog::log('relay.revoked', userId: $revokedByUserId, auditable: $relay, newValues: ['name' => $relay->name], isCritical: true);
    }

    private function generateCode(): string
    {
        $characters = '';
        $lastIndex = strlen(self::CODE_ALPHABET) - 1;

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $characters .= self::CODE_ALPHABET[random_int(0, $lastIndex)];
        }

        return implode('-', str_split($characters, 4));
    }

    /**
     * Codes are typed by hand, so case and separators don't matter.
     */
    private function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
