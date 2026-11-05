<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\EventConfiguration;
use Illuminate\Support\Str;

/**
 * Issues one-time enrollment codes and trades them for agent tokens.
 */
class AgentEnrollmentService
{
    public const CODE_LIFETIME_MINUTES = 15;

    /**
     * Letters and digits that can't be misread for one another.
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 12;

    /**
     * Create an agent waiting to enroll. The code is only available here,
     * because only its hash is stored.
     *
     * @return array{agent: Agent, code: string}
     */
    public function issue(EventConfiguration $config, string $name, ?int $createdByUserId = null): array
    {
        $code = $this->generateCode();

        $agent = Agent::create([
            'event_configuration_id' => $config->id,
            'name' => $name,
            'enrollment_code_hash' => Agent::hashSecret($this->normalizeCode($code)),
            'enrollment_expires_at' => now()->addMinutes(self::CODE_LIFETIME_MINUTES),
            'created_by_user_id' => $createdByUserId,
        ]);

        AuditLog::log('agent.code_issued', userId: $createdByUserId, auditable: $agent, newValues: ['name' => $name]);

        return ['agent' => $agent, 'code' => $code];
    }

    /**
     * Trade an enrollment code for a token. A code works once, and only until
     * it expires. The token is only available here, because only its hash is
     * stored.
     *
     * @return array{agent: Agent, token: string}|null Null when the code can't be used
     */
    public function redeem(string $code, ?string $agentVersion = null): ?array
    {
        $codeHash = Agent::hashSecret($this->normalizeCode($code));

        $agent = Agent::active()
            ->where('enrollment_code_hash', $codeHash)
            ->where('enrollment_expires_at', '>', now())
            ->first();

        if ($agent === null) {
            return null;
        }

        $token = 'fdca_'.Str::random(64);

        // Conditional on the code still being there, so two requests racing
        // with the same code can't both get a token.
        $claimed = Agent::query()
            ->whereKey($agent->id)
            ->where('enrollment_code_hash', $codeHash)
            ->update([
                'enrollment_code_hash' => null,
                'enrollment_expires_at' => null,
                'token_hash' => Agent::hashSecret($token),
                'agent_version' => $agentVersion,
                'enrolled_at' => now(),
                'last_seen_at' => now(),
            ]);

        if ($claimed !== 1) {
            return null;
        }

        AuditLog::log('agent.enrolled', auditable: $agent, newValues: ['agent_version' => $agentVersion]);

        return ['agent' => $agent->refresh(), 'token' => $token];
    }

    /**
     * Stop an agent's token or unused code from working.
     */
    public function revoke(Agent $agent, ?int $revokedByUserId = null): void
    {
        $agent->update([
            'revoked_at' => now(),
            'enrollment_code_hash' => null,
            'enrollment_expires_at' => null,
        ]);

        AuditLog::log('agent.revoked', userId: $revokedByUserId, auditable: $agent, isCritical: true);
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
