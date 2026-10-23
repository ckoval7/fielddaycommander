<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Profile;

use App\Models\AuditLog;
use App\Models\OperatingSession;
use App\Models\Setting;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mary\Traits\Toast;

class UserProfile extends Component
{
    use Toast;

    public string $activeTab = 'profile';

    // Profile tab properties
    public string $call_sign = '';

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    /**
     * Current password, required only when the email address changes.
     */
    public string $emailChangePassword = '';

    /**
     * The email address as last saved. The view compares the email field with
     * it to decide whether to ask for the current password.
     */
    #[Locked]
    public string $savedEmail = '';

    public ?string $license_class = null;

    public ?string $preferred_timezone = null;

    public bool $is_youth = false;

    public bool $is_cpr_aed_trained = false;

    public bool $event_notifications = true;

    public bool $system_announcements = true;

    // Notification category preferences
    public bool $notify_new_section = true;

    public bool $notify_guestbook = true;

    public bool $notify_photos = true;

    public bool $notify_station_status = true;

    public bool $notify_qso_milestone = true;

    public bool $notify_equipment = true;

    public bool $notify_bulletin_reminder = true;

    public bool $notify_shift_checkin_reminder = true;

    public bool $notifyWeatherAlert = true;

    public bool $notifyShiftConfirmation = true;

    // Shift reminder settings
    public bool $shift_reminder_email = false;

    public bool $weatherAlertEmail = false;

    public ?int $shiftReminderMinute = null;

    // Bulletin reminder settings (relocated from W1awBulletinForm)
    public ?int $bulletinReminderMinute = null;

    // Activity tab properties
    public int $activityLimit = 15;

    // Security tab properties
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    // 2FA properties. Locked so only server actions that verified the
    // password can reveal the TOTP secret or the recovery codes.
    #[Locked]
    public bool $showingQrCode = false;

    #[Locked]
    public bool $showingRecoveryCodes = false;

    public string $twoFactorCode = '';

    /**
     * Each 2FA action has its own password field so typing in one box never fills another.
     */
    public string $enableTwoFactorPassword = '';

    public string $recoveryCodesPassword = '';

    public string $disableTwoFactorPassword = '';

    /**
     * The 2FA action waiting for a password: 'view-codes', 'regenerate-codes', 'disable', or null.
     */
    #[Locked]
    public ?string $confirmingTwoFactorAction = null;

    /** @var list<string> */
    private const CURRENT_PASSWORD = 'current password';

    private const TWO_FACTOR_ACTIONS = ['view-codes', 'regenerate-codes', 'disable'];

    public function mount(): void
    {
        if (request()->query('tab') === 'security') {
            $this->activeTab = 'security';
        }

        $user = auth()->user();

        // Load profile data
        $this->call_sign = $user->call_sign;
        $this->first_name = $user->first_name;
        $this->last_name = $user->last_name;
        $this->email = $user->email;
        $this->savedEmail = $user->email;
        $this->license_class = $user->license_class;
        $this->preferred_timezone = $user->preferred_timezone ?? Setting::get('timezone', config('app.timezone'));
        $this->is_youth = (bool) $user->is_youth;
        $this->is_cpr_aed_trained = (bool) $user->is_cpr_aed_trained;

        // Load notification preferences
        $preferences = $user->notification_preferences ?? [];
        $this->event_notifications = $preferences['event_notifications'] ?? true;
        $this->system_announcements = $preferences['system_announcements'] ?? true;

        // Load category preferences
        $categories = $preferences['categories'] ?? [];
        $this->notify_new_section = $categories['new_section'] ?? true;
        $this->notify_guestbook = $categories['guestbook'] ?? true;
        $this->notify_photos = $categories['photos'] ?? true;
        $this->notify_station_status = $categories['station_status'] ?? true;
        $this->notify_qso_milestone = $categories['qso_milestone'] ?? true;
        $this->notify_equipment = $categories['equipment'] ?? true;
        $this->notify_bulletin_reminder = $categories['bulletin_reminder'] ?? true;
        $this->notify_shift_checkin_reminder = $categories['shift_checkin_reminder'] ?? true;
        $this->notifyWeatherAlert = $categories['weather_alert'] ?? true;
        $this->notifyShiftConfirmation = $categories['shift_confirmation'] ?? true;
        $this->shift_reminder_email = $preferences['shift_reminder_email'] ?? false;
        $this->weatherAlertEmail = $preferences['weather_alert_email'] ?? false;
    }

    public function saveProfile(): void
    {
        $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'license_class' => ['nullable', 'string', 'in:Technician,General,Advanced,Extra'],
            'preferred_timezone' => ['nullable', 'string', 'timezone:all'],
            'is_youth' => ['boolean'],
            'is_cpr_aed_trained' => ['boolean'],
        ]);

        $user = auth()->user();

        if (strcasecmp($this->email, $user->email) !== 0) {
            $this->validate([
                'emailChangePassword' => ['required', 'string', 'current_password:web'],
            ]);
        }

        $this->emailChangePassword = '';

        // Use Fortify's update action
        app(UpdatesUserProfileInformation::class)->update(
            $user,
            [
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'email' => $this->email,
                'license_class' => $this->license_class,
                'preferred_timezone' => $this->preferred_timezone,
                'is_youth' => $this->is_youth,
                'is_cpr_aed_trained' => $this->is_cpr_aed_trained,
                'notification_preferences' => [
                    'event_notifications' => $this->event_notifications,
                    'system_announcements' => $this->system_announcements,
                    'shift_reminder_email' => $this->shift_reminder_email,
                    'weather_alert_email' => $this->weatherAlertEmail,
                    'bulletin_reminder_minutes' => $user->getBulletinReminderMinutes(),
                    'shift_reminder_minutes' => $user->getShiftReminderMinutes(),
                    'categories' => [
                        'new_section' => $this->notify_new_section,
                        'guestbook' => $this->notify_guestbook,
                        'photos' => $this->notify_photos,
                        'station_status' => $this->notify_station_status,
                        'qso_milestone' => $this->notify_qso_milestone,
                        'equipment' => $this->notify_equipment,
                        'bulletin_reminder' => $this->notify_bulletin_reminder,
                        'shift_checkin_reminder' => $this->notify_shift_checkin_reminder,
                        'weather_alert' => $this->notifyWeatherAlert,
                        'shift_confirmation' => $this->notifyShiftConfirmation,
                    ],
                ],
            ]
        );

        $this->savedEmail = $user->fresh()->email;
        $this->email = $this->savedEmail;

        $this->success('Profile updated.');
    }

    public function toggleAllCategories(bool $enabled): void
    {
        $this->notify_new_section = $enabled;
        $this->notify_guestbook = $enabled;
        $this->notify_photos = $enabled;
        $this->notify_station_status = $enabled;
        $this->notify_qso_milestone = $enabled;
        $this->notify_equipment = $enabled;
        $this->notify_bulletin_reminder = $enabled;
        $this->notify_shift_checkin_reminder = $enabled;
        $this->notifyWeatherAlert = $enabled;
        $this->notifyShiftConfirmation = $enabled;
    }

    #[Computed]
    public function allCategoriesEnabled(): bool
    {
        return $this->notify_new_section
            && $this->notify_guestbook
            && $this->notify_photos
            && $this->notify_station_status
            && $this->notify_qso_milestone
            && $this->notify_equipment
            && $this->notify_bulletin_reminder
            && $this->notify_shift_checkin_reminder
            && $this->notifyWeatherAlert
            && $this->notifyShiftConfirmation;
    }

    public function getShiftReminderMinutesProperty(): array
    {
        return auth()->user()->getShiftReminderMinutes();
    }

    public function addShiftReminderMinute(): void
    {
        $this->validate([
            'shiftReminderMinute' => 'required|integer|min:1|max:60',
        ]);

        $current = auth()->user()->getShiftReminderMinutes();

        if (count($current) >= 5) {
            $this->addError('shiftReminderMinute', 'Remove a reminder before adding another. You can set up to 5.');

            return;
        }

        if (in_array((int) $this->shiftReminderMinute, $current, true)) {
            $this->addError('shiftReminderMinute', 'Choose a different time. This reminder is already set.');

            return;
        }

        $current[] = (int) $this->shiftReminderMinute;
        sort($current);
        auth()->user()->setShiftReminderMinutes($current);

        $this->shiftReminderMinute = null;
        $this->success('Shift reminder added.');
    }

    public function removeShiftReminderMinute(int $minutes): void
    {
        $current = auth()->user()->getShiftReminderMinutes();
        $current = array_values(array_filter($current, fn ($m) => $m !== $minutes));
        auth()->user()->setShiftReminderMinutes($current);
        $this->success('Shift reminder removed.');
    }

    public function getBulletinReminderMinutesProperty(): array
    {
        return auth()->user()->getBulletinReminderMinutes();
    }

    public function addBulletinReminderMinute(): void
    {
        $this->validate([
            'bulletinReminderMinute' => 'required|integer|min:1|max:60',
        ]);

        $current = auth()->user()->getBulletinReminderMinutes();

        if (count($current) >= 5) {
            $this->addError('bulletinReminderMinute', 'Remove a reminder before adding another. You can set up to 5.');

            return;
        }

        if (in_array((int) $this->bulletinReminderMinute, $current, true)) {
            $this->addError('bulletinReminderMinute', 'Choose a different time. This reminder is already set.');

            return;
        }

        $current[] = (int) $this->bulletinReminderMinute;
        sort($current);
        auth()->user()->setBulletinReminderMinutes($current);

        $this->bulletinReminderMinute = null;
        $this->success('Bulletin reminder added.');
    }

    public function removeBulletinReminderMinute(int $minutes): void
    {
        $current = auth()->user()->getBulletinReminderMinutes();
        $current = array_values(array_filter($current, fn ($m) => $m !== $minutes));
        auth()->user()->setBulletinReminderMinutes($current);
        $this->success('Bulletin reminder removed.');
    }

    public function changePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            app(UpdatesUserPasswords::class)->update(
                auth()->user(),
                [
                    'current_password' => $this->current_password,
                    'password' => $this->password,
                    'password_confirmation' => $this->password_confirmation,
                ]
            );

            // Reset password fields
            $this->current_password = '';
            $this->password = '';
            $this->password_confirmation = '';

            $this->success('Password changed.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            $this->error('Password change failed.', 'Check the password fields and try again.');
        }
    }

    public function logoutOtherSessions(): void
    {
        $this->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
        ]);

        auth()->logoutOtherDevices($this->current_password);

        \DB::table('sessions')
            ->where('user_id', auth()->id())
            ->where('id', '!=', session()->getId())
            ->delete();

        $this->current_password = '';

        $this->success('Other sessions signed out.');
    }

    /**
     * End one of the user's other login sessions. The current session and
     * other users' sessions are never touched.
     */
    public function revokeSession(string $sessionId): void
    {
        if ($sessionId === session()->getId()) {
            return;
        }

        \DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', auth()->id())
            ->delete();

        $this->success('Session signed out.');
    }

    public function enableTwoFactor(): void
    {
        if (config('auth-security.2fa_mode') === 'disabled') {
            $this->dispatch('toast', title: 'Error', description: 'Two-factor authentication is disabled by your administrator.', icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        $this->validate([
            'enableTwoFactorPassword' => ['required', 'string', 'current_password:web'],
        ]);

        app(EnableTwoFactorAuthentication::class)(auth()->user());

        auth()->user()->refresh();

        $this->showingQrCode = true;
        $this->showingRecoveryCodes = false;
        $this->enableTwoFactorPassword = '';
    }

    /**
     * Abandon a setup that was started but never confirmed. Disabling a
     * confirmed 2FA setup goes through disableTwoFactor(), which needs the
     * current password.
     */
    public function cancelTwoFactorSetup(): void
    {
        if (config('auth-security.2fa_mode') === 'required') {
            return;
        }

        $user = auth()->user();

        if (! $user->two_factor_secret || $user->two_factor_confirmed_at) {
            $this->showingQrCode = false;
            $this->twoFactorCode = '';

            return;
        }

        app(DisableTwoFactorAuthentication::class)($user);

        auth()->user()->refresh();

        $this->showingQrCode = false;
        $this->twoFactorCode = '';
    }

    public function confirmTwoFactor(): void
    {
        $this->validate([
            'twoFactorCode' => ['required', 'string'],
        ]);

        try {
            app(ConfirmTwoFactorAuthentication::class)(auth()->user(), $this->twoFactorCode);
        } catch (ValidationException $e) {
            $this->addError('twoFactorCode', $e->errors()['code'][0] ?? 'That code is not valid. Try the current code from your app.');

            return;
        }

        auth()->user()->refresh();

        $this->showingQrCode = false;
        $this->showingRecoveryCodes = true;
        $this->twoFactorCode = '';

        $this->success('Two-factor authentication enabled.');
    }

    public function disableTwoFactor(): void
    {
        if (config('auth-security.2fa_mode') === 'required') {
            $this->dispatch('toast', title: 'Error', description: 'Two-factor authentication is required and cannot be disabled.', icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        $this->validate([
            'disableTwoFactorPassword' => ['required', 'string', 'current_password:web'],
        ]);

        app(DisableTwoFactorAuthentication::class)(auth()->user());

        auth()->user()->refresh();

        $this->showingQrCode = false;
        $this->showingRecoveryCodes = false;
        $this->disableTwoFactorPassword = '';
        $this->confirmingTwoFactorAction = null;

        $this->success('Two-factor authentication disabled.');
    }

    /**
     * Show the password box for a 2FA action. Only one box is shown at a time.
     */
    public function startTwoFactorAction(string $action): void
    {
        if (! in_array($action, self::TWO_FACTOR_ACTIONS, true)) {
            return;
        }

        $this->resetTwoFactorConfirmation();
        $this->confirmingTwoFactorAction = $action;
    }

    public function cancelTwoFactorAction(): void
    {
        $this->resetTwoFactorConfirmation();
    }

    private function resetTwoFactorConfirmation(): void
    {
        $this->confirmingTwoFactorAction = null;
        $this->recoveryCodesPassword = '';
        $this->disableTwoFactorPassword = '';
        $this->resetValidation(['recoveryCodesPassword', 'disableTwoFactorPassword']);
    }

    public function showRecoveryCodes(): void
    {
        $this->validate([
            'recoveryCodesPassword' => ['required', 'string', 'current_password:web'],
        ]);

        $this->showingRecoveryCodes = true;
        $this->recoveryCodesPassword = '';
        $this->confirmingTwoFactorAction = null;
    }

    public function hideRecoveryCodes(): void
    {
        $this->showingRecoveryCodes = false;
        $this->resetTwoFactorConfirmation();
    }

    public function regenerateRecoveryCodes(): void
    {
        $this->validate([
            'recoveryCodesPassword' => ['required', 'string', 'current_password:web'],
        ]);

        app(GenerateNewRecoveryCodes::class)(auth()->user());

        auth()->user()->refresh();

        $this->showingRecoveryCodes = true;
        $this->recoveryCodesPassword = '';
        $this->confirmingTwoFactorAction = null;

        $this->success('Recovery codes regenerated.');
    }

    public function loadMoreActivity(): void
    {
        $this->activityLimit += 15;
    }

    public function render()
    {
        $user = auth()->user();

        // Get login sessions
        $sessions = \DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderBy('last_activity', 'desc')
            ->get();

        // Get operating sessions for this user
        $operatingSessions = OperatingSession::query()
            ->forUser($user->id)
            ->with(['station.eventConfiguration.event', 'band', 'mode'])
            ->latest('start_time')
            ->limit(20)
            ->get();

        // Get activity log for this user
        $activityLog = AuditLog::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit($this->activityLimit)
            ->get();

        return view('livewire.profile.user-profile', [
            'user' => $user,
            'sessions' => $sessions,
            'operatingSessions' => $operatingSessions,
            'activityLog' => $activityLog,
        ])->layout('layouts.app');
    }

    /**
     * Get friendly field names for validation messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'shiftReminderMinute' => 'reminder minutes',
            'bulletinReminderMinute' => 'reminder minutes',
            'twoFactorCode' => 'authentication code',
            'emailChangePassword' => self::CURRENT_PASSWORD,
            'enableTwoFactorPassword' => self::CURRENT_PASSWORD,
            'recoveryCodesPassword' => self::CURRENT_PASSWORD,
            'disableTwoFactorPassword' => self::CURRENT_PASSWORD,
        ];
    }
}
