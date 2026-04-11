<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserResolverService
{
    /** @var array<string, User> */
    private array $cache = [];

    /**
     * Find a user by callsign, or create a locked stub account.
     */
    public function resolveOrCreate(string $callsign): User
    {
        if (trim($callsign) === '') {
            throw new \InvalidArgumentException('Callsign cannot be empty.');
        }

        $callsign = strtoupper($callsign);

        if (isset($this->cache[$callsign])) {
            return $this->cache[$callsign];
        }

        return $this->cache[$callsign] = User::firstOrCreate(
            ['call_sign' => $callsign],
            [
                'first_name' => $callsign,
                'last_name' => '(Imported)',
                'email' => strtolower($callsign).'@imported.local',
                'password' => Hash::make(Str::random(64)),
                'user_role' => 'locked',
                'account_locked_at' => now(),
                'requires_password_change' => true,
            ]
        );
    }
}
