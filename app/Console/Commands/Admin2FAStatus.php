<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class Admin2FAStatus extends Command
{
    protected $signature = 'app:admin-2fa-status';

    protected $description = 'Check admin 2FA bypass status';

    public function handle(): int
    {
        $admin = User::where('call_sign', 'SYSTEM')->first();

        if (! $admin) {
            $this->error('System admin account not found!');

            return 1;
        }

        if ($admin->two_factor_bypass_enabled &&
            $admin->two_factor_bypass_expires_at?->isFuture()) {
            $this->info('2FA Bypass: ACTIVE');
            $this->info('Expires: '.$admin->two_factor_bypass_expires_at);
            $this->info('Reason: '.$admin->two_factor_bypass_reason);
        } else {
            $this->info('2FA Bypass: Not active');
        }

        return 0;
    }
}
