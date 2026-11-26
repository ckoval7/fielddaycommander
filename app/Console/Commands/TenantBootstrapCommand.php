<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\HostedMode;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use JsonException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sets up a hosted club in place of the setup wizard.
 *
 * The hosting service writes the club's details to a JSON file (a Secret
 * the container mounts) and the entrypoint runs this on boot. It creates
 * the organization, the settings the wizard would have asked for, the
 * owner's System Administrator account with an invitation to choose a
 * password, and the SYSTEM support account with a password only the
 * hosting service knows. Once setup is complete it does nothing, so it is
 * safe to run on every boot.
 */
class TenantBootstrapCommand extends Command
{
    protected $signature = 'tenant:bootstrap {--from= : Path to the bootstrap JSON file}';

    protected $description = 'Set up a hosted club from the hosting service\'s bootstrap file';

    public function handle(): int
    {
        if (! HostedMode::isEnabled()) {
            $this->error('tenant:bootstrap only runs with HOSTED_MODE=true. Self-hosted installs use the setup wizard.');

            return self::FAILURE;
        }

        if (Setting::where('key', 'setup_completed')->value('value') === 'true') {
            $this->info('Setup is already complete, skipping.');

            return self::SUCCESS;
        }

        $path = (string) ($this->option('from') ?: config('hosted.bootstrap_file'));

        if (! File::isReadable($path)) {
            $this->error("Bootstrap file {$path} is missing or unreadable.");

            return self::FAILURE;
        }

        try {
            $input = json_decode(File::get($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error("Bootstrap file {$path} is not valid JSON: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $validator = Validator::make(is_array($input) ? self::normalize($input) : [], self::rules());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $owner = $this->bootstrap($validator->validated());

        $this->info("Set up the club. {$owner->call_sign} is its System Administrator.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function bootstrap(array $data): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return DB::transaction(function () use ($data): User {
            $system = User::withTrashed()->updateOrCreate(
                ['call_sign' => User::SYSTEM_CALL_SIGN],
                [
                    'first_name' => 'System',
                    'last_name' => 'Administrator',
                    'email' => 'admin@localhost',
                    'password' => Hash::make($data['system_password']),
                    'user_role' => 'admin',
                    'email_verified_at' => now(),
                ],
            );
            $system->syncRoles(['Config Only']);

            $organization = Organization::create([
                'name' => $data['organization']['name'],
                'callsign' => $data['organization']['callsign'] ?? null,
                'email' => $data['organization']['email'] ?? null,
                'is_active' => true,
            ]);

            $owner = User::create([
                'call_sign' => $data['owner']['call_sign'],
                'first_name' => $data['owner']['first_name'],
                'last_name' => $data['owner']['last_name'],
                'email' => $data['owner']['email'],
                'password' => Hash::make(str()->random(64)),
            ]);
            $owner->assignRole('System Administrator');

            UserInvitation::create([
                'user_id' => $owner->id,
                'token' => $data['owner_invitation_token'],
                'expires_at' => Carbon::parse($data['owner_invitation_expires_at']),
            ]);

            $dateFormat = $data['date_format'] ?? 'Y-m-d';
            $timeFormat = $data['time_format'] ?? 'H:i:s';

            Setting::set('default_organization_id', $organization->id);
            Setting::set('site_name', $data['site_name'] ?? $organization->name);
            Setting::set('timezone', $data['timezone']);
            Setting::set('date_format', $dateFormat);
            Setting::set('time_format', $timeFormat);
            Setting::set('datetime_format', $dateFormat.' '.$timeFormat);

            if (filled($data['organization']['email'] ?? null)) {
                Setting::set('contact_email', $data['organization']['email']);
            }

            Setting::set('setup_completed', 'true');

            AuditLog::log('system.setup.completed', userId: $system->id, auditable: $organization, newValues: [
                'method' => 'tenant:bootstrap',
                'owner' => $owner->call_sign,
            ], ipAddress: '127.0.0.1');

            return $owner;
        });
    }

    /**
     * Uppercase callsigns and drop blank optional values before validating.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function normalize(array $input): array
    {
        foreach (['organization.callsign', 'owner.call_sign'] as $key) {
            $value = mb_strtoupper(trim((string) data_get($input, $key, '')));
            data_set($input, $key, $value !== '' ? $value : null);
        }

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    private static function rules(): array
    {
        return [
            'organization' => ['required', 'array'],
            'organization.name' => ['required', 'string', 'max:255'],
            'organization.callsign' => ['nullable', 'string', 'regex:/^[A-Z0-9]{3,10}$/'],
            'organization.email' => ['nullable', 'email', 'max:255'],
            'site_name' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'date_format' => ['nullable', 'string', 'in:Y-m-d,m/d/Y,d/m/Y,d.m.Y'],
            'time_format' => ['nullable', 'string', 'in:H:i:s,h:i:s A'],
            'owner' => ['required', 'array'],
            'owner.first_name' => ['required', 'string', 'max:255'],
            'owner.last_name' => ['required', 'string', 'max:255'],
            'owner.call_sign' => ['required', 'string', 'regex:/^[A-Z0-9\/]{3,15}$/', 'not_in:'.User::SYSTEM_CALL_SIGN],
            'owner.email' => ['required', 'email', 'max:255'],
            'owner_invitation_token' => ['required', 'string', 'min:40', 'max:255'],
            'owner_invitation_expires_at' => ['required', 'date', 'after:now'],
            'system_password' => ['required', 'string', 'min:32'],
        ];
    }
}
