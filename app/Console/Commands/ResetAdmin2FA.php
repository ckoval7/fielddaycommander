<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

class ResetAdmin2FA extends Command
{
    protected $signature = 'app:reset-admin-2fa {--reason=Emergency 2FA reset}';

    protected $description = 'Enable emergency 2FA bypass for system admin account';

    public function handle(): int
    {
        $admin = User::where('call_sign', User::SYSTEM_CALL_SIGN)->first();

        if (! $admin) {
            $this->error('System admin account not found!');

            return 1;
        }

        $this->warn('⚠️  This will allow system admin to bypass 2FA on next login.');
        $this->warn('⚠️  The bypass will expire in 1 hour if not used.');

        if (! $this->confirm('Continue?', false)) {
            $this->info('Cancelled.');

            return 0;
        }

        $admin->update([
            'two_factor_bypass_enabled' => true,
            'two_factor_bypass_expires_at' => now()->addHour(),
            'two_factor_bypass_reason' => $this->option('reason'),
        ]);

        AuditLog::log(
            action: 'admin.2fa.bypass_enabled',
            userId: $admin->id,
            newValues: ['reason' => $this->option('reason')],
            isCritical: true
        );

        $this->info('✓ Admin 2FA bypass enabled for 1 hour');
        $this->warn('⚠️  Admin MUST set up new 2FA on next login');
        $this->info('⚠️  This bypass expires at: '.$admin->two_factor_bypass_expires_at);

        return 0;
    }
}
