<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

class ResetAdmin2FA extends Command
{
    protected $signature = 'app:reset-admin-2fa {--reason=Emergency 2FA reset}';

    protected $description = 'Remove the system admin account\'s 2FA so it can be set up again';

    public function handle(): int
    {
        $admin = User::where('call_sign', User::SYSTEM_CALL_SIGN)->first();

        if (! $admin) {
            $this->error('System admin account not found!');

            return 1;
        }

        $this->warn('⚠️  This removes the system admin\'s authenticator and recovery codes.');
        $this->warn('⚠️  The next login will not ask for a 2FA code.');

        if (! $this->confirm('Continue?', false)) {
            $this->info('Cancelled.');

            return 0;
        }

        $admin->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditLog::log(
            action: 'admin.2fa.reset',
            userId: $admin->id,
            newValues: ['reason' => $this->option('reason')],
            isCritical: true
        );

        $this->info('✓ Admin 2FA has been reset');
        $this->warn('⚠️  Set up 2FA again right after logging in');

        return 0;
    }
}
