<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Finishes first-time setup: the SYSTEM account, the club's organization,
 * branding and preferences, and optionally a first named System
 * Administrator with an invitation to choose a password.
 *
 * The setup wizard and the setup:apply command both end here, so an install
 * set up from a file is the same as one set up in the browser.
 */
class InitialSetupService
{
    /**
     * Whether first-time setup has already been completed.
     */
    public function isComplete(): bool
    {
        return Setting::where('key', 'setup_completed')->value('value') === 'true';
    }

    /**
     * Complete setup in one transaction.
     *
     * @param  array{
     *     system_password?: string|null,
     *     organization: array{name: string, callsign?: string|null, email?: string|null, phone?: string|null},
     *     site_name: string,
     *     site_tagline?: string|null,
     *     site_logo_path?: string|null,
     *     timezone: string,
     *     date_format: string,
     *     time_format: string,
     *     contact_email?: string|null,
     *     admin?: array{call_sign: string, first_name: string, last_name: string, email: string, invitation_token: string, invitation_expires_at: Carbon}|null,
     * }  $data
     * @param  string  $method  How setup was done, for the audit log.
     */
    public function complete(array $data, string $method): CompletedSetup
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return DB::transaction(function () use ($data, $method): CompletedSetup {
            $system = $this->setUpSystemAccount($data['system_password'] ?? null);

            $organization = Organization::create([
                'name' => $data['organization']['name'],
                'callsign' => $data['organization']['callsign'] ?? null,
                'email' => $data['organization']['email'] ?? null,
                'phone' => $data['organization']['phone'] ?? null,
                'is_active' => true,
            ]);

            $this->saveSettings($data, $organization);

            $invitation = filled($data['admin'] ?? null)
                ? $this->createFirstAdmin($data['admin'])
                : null;

            Setting::set('setup_completed', 'true');

            AuditLog::log('system.setup.completed', userId: $system->id, auditable: $organization, newValues: array_filter([
                'method' => $method,
                'admin' => $invitation?->user->call_sign,
            ]), ipAddress: request()->ip() ?? '127.0.0.1');

            return new CompletedSetup($organization, $system, $invitation);
        });
    }

    /**
     * Create or update SYSTEM. Without a password it gets a random one
     * nobody knows, and opens only through a one-time sign-in link.
     */
    private function setUpSystemAccount(?string $password): User
    {
        $system = User::withTrashed()->updateOrCreate(
            ['call_sign' => User::SYSTEM_CALL_SIGN],
            [
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'email' => 'admin@localhost',
                'password' => Hash::make(filled($password) ? $password : str()->random(64)),
                'user_role' => 'admin',
                'email_verified_at' => now(),
            ],
        );

        $system->syncRoles(['Config Only']);

        return $system;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveSettings(array $data, Organization $organization): void
    {
        Setting::set('default_organization_id', $organization->id);
        Setting::set('site_name', $data['site_name']);

        if (filled($data['site_tagline'] ?? null)) {
            Setting::set('site_tagline', $data['site_tagline']);
        }

        if (filled($data['site_logo_path'] ?? null)) {
            Setting::set('site_logo_path', $data['site_logo_path']);
        }

        Setting::set('timezone', $data['timezone']);
        Setting::set('date_format', $data['date_format']);
        Setting::set('time_format', $data['time_format']);
        Setting::set('datetime_format', $data['date_format'].' '.$data['time_format']);

        if (filled($data['contact_email'] ?? null)) {
            Setting::set('contact_email', $data['contact_email']);
        }
    }

    /**
     * Create the first named System Administrator with a random password
     * and an invitation to choose their own.
     *
     * @param  array{call_sign: string, first_name: string, last_name: string, email: string, invitation_token: string, invitation_expires_at: Carbon}  $admin
     */
    private function createFirstAdmin(array $admin): UserInvitation
    {
        $user = User::create([
            'call_sign' => $admin['call_sign'],
            'first_name' => $admin['first_name'],
            'last_name' => $admin['last_name'],
            'email' => $admin['email'],
            'password' => Hash::make(str()->random(64)),
        ]);
        $user->assignRole('System Administrator');

        return UserInvitation::create([
            'user_id' => $user->id,
            'token' => $admin['invitation_token'],
            'expires_at' => $admin['invitation_expires_at'],
        ])->setRelation('user', $user);
    }
}
