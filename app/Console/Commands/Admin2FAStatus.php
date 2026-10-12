<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class Admin2FAStatus extends Command
{
    protected $signature = 'app:admin-2fa-status';

    protected $description = 'Check whether the system admin account has 2FA set up';

    public function handle(): int
    {
        $admin = User::where('call_sign', User::SYSTEM_CALL_SIGN)->first();

        if (! $admin) {
            $this->error('System admin account not found!');

            return 1;
        }

        if ($admin->two_factor_confirmed_at) {
            $this->info('2FA: Enabled');
            $this->info('Confirmed: '.$admin->two_factor_confirmed_at);
        } elseif ($admin->two_factor_secret) {
            $this->info('2FA: Pending confirmation');
        } else {
            $this->info('2FA: Not set up');
        }

        return 0;
    }
}
