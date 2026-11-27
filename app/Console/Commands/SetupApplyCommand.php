<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Http\Requests\SetupStep3Request;
use App\Models\User;
use App\Services\InitialSetupService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use JsonException;

/**
 * Completes first-time setup from a JSON file instead of the setup wizard.
 *
 * Docker and Helm installs can run it on boot (SETUP_FILE), deploy.sh runs
 * it with --setup-file, and anyone can pipe a file in on the command line.
 * Once setup is complete it does nothing, so running it on every boot is
 * safe. DOCKER.md describes the file.
 */
class SetupApplyCommand extends Command
{
    protected $signature = 'setup:apply
        {file : Path to the setup JSON file, or - for standard input}';

    protected $description = 'Complete first-time setup from a JSON file';

    /**
     * How long the first admin's invitation lasts when the file doesn't say,
     * matching invitations sent from User Management.
     */
    private const int DEFAULT_INVITATION_HOURS = 72;

    public function handle(InitialSetupService $setup): int
    {
        if ($setup->isComplete()) {
            $this->info('Setup is already complete. Nothing to do.');

            return self::SUCCESS;
        }

        $data = $this->readSetupFile((string) $this->argument('file'));

        if ($data === null) {
            return self::FAILURE;
        }

        $result = $setup->complete($this->prepare($data), method: 'setup:apply');

        $this->info("Setup is complete for {$result->organization->name}.");

        if ($result->adminInvitation !== null) {
            $this->info("{$result->adminInvitation->user->call_sign} can choose a password for their System Administrator account at:");
            $this->line(route('invitation.show', $result->adminInvitation->token));
            $this->line('The link expires '.$result->adminInvitation->expires_at->toDayDateTimeString().'.');
        }

        if (blank($data['system_password'] ?? null)) {
            $this->line('SYSTEM has a random password. Sign in with: php artisan user:sign-in-link');
        }

        return self::SUCCESS;
    }

    /**
     * The validated setup data, or null after reporting why the file can't
     * be used.
     *
     * @return array<string, mixed>|null
     */
    private function readSetupFile(string $path): ?array
    {
        $input = $this->decode($path);

        if ($input === null) {
            return null;
        }

        $validator = Validator::make(self::normalize($input), self::rules());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return null;
        }

        return $validator->validated();
    }

    /**
     * The decoded JSON ([] when it isn't an object, so that validation
     * reports what's missing), or null after reporting that it is missing,
     * unreadable or not JSON.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $path): ?array
    {
        if ($path === '-') {
            $contents = (string) stream_get_contents(STDIN);
        } elseif (File::isReadable($path)) {
            $contents = File::get($path);
        } else {
            $this->error("Setup file {$path} is missing or unreadable.");

            return null;
        }

        try {
            $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error("Setup file {$path} is not valid JSON: {$exception->getMessage()}");

            return null;
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Fill in defaults and shape the validated file for InitialSetupService.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data): array
    {
        $data['site_name'] ??= $data['organization']['name'];
        $data['date_format'] ??= 'Y-m-d';
        $data['time_format'] ??= 'H:i:s';

        if (isset($data['admin'])) {
            $data['admin']['invitation_token'] ??= str()->random(64);
            $data['admin']['invitation_expires_at'] = isset($data['admin']['invitation_expires_at'])
                ? Carbon::parse($data['admin']['invitation_expires_at'])
                : now()->addHours(self::DEFAULT_INVITATION_HOURS);
        }

        return $data;
    }

    /**
     * Uppercase callsigns and drop blank optional values before validating.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function normalize(array $input): array
    {
        foreach (['organization.callsign', 'admin.call_sign'] as $key) {
            if (data_get($input, $key) !== null) {
                $value = mb_strtoupper(trim((string) data_get($input, $key)));
                data_set($input, $key, $value !== '' ? $value : null);
            }
        }

        return $input;
    }

    /**
     * The wizard's rules where the file asks the same questions.
     *
     * @return array<string, mixed>
     */
    private static function rules(): array
    {
        $wizard = (new SetupStep3Request)->rules();

        return [
            'system_password' => ['nullable', 'string', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            'organization' => ['required', 'array'],
            'organization.name' => $wizard['organization_name'],
            'organization.callsign' => $wizard['organization_callsign'],
            'organization.email' => $wizard['organization_email'],
            'organization.phone' => $wizard['organization_phone'],
            'site_name' => ['nullable', 'string', 'max:100'],
            'site_tagline' => ['nullable', 'string', 'max:255'],
            'timezone' => $wizard['timezone'],
            'date_format' => array_replace($wizard['date_format'], [0 => 'nullable']),
            'time_format' => array_replace($wizard['time_format'], [0 => 'nullable']),
            'contact_email' => $wizard['contact_email'],
            'admin' => ['nullable', 'array'],
            'admin.first_name' => ['required_with:admin', 'string', 'max:255'],
            'admin.last_name' => ['required_with:admin', 'string', 'max:255'],
            'admin.call_sign' => ['required_with:admin', 'string', 'regex:/^[A-Z0-9\/]{3,15}$/', 'not_in:'.User::SYSTEM_CALL_SIGN, 'unique:users,call_sign'],
            'admin.email' => ['required_with:admin', 'email', 'max:255', 'unique:users,email'],
            'admin.invitation_token' => ['nullable', 'string', 'min:40', 'max:255'],
            'admin.invitation_expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
