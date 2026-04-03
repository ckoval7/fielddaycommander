<?php

namespace App\Livewire\Profile;

use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Computed;
use Livewire\Component;

class UserProfile extends Component
{
    use \Mary\Traits\Toast;

    public string $activeTab = 'profile';

    // Profile tab properties
    public string $call_sign = '';

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public ?string $license_class = null;

    public ?string $preferred_timezone = null;

    public bool $is_youth = false;

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

    // Security tab properties
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    // 2FA properties
    public bool $showingQrCode = false;

    public bool $showingRecoveryCodes = false;

    public string $twoFactorCode = '';

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
        $this->license_class = $user->license_class;
        $this->preferred_timezone = $user->preferred_timezone ?? \App\Models\Setting::get('timezone', config('app.timezone'));
        $this->is_youth = (bool) $user->is_youth;

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
        ]);

        $user = auth()->user();

        // Use Fortify's update action
        app(\Laravel\Fortify\Contracts\UpdatesUserProfileInformation::class)->update(
            $user,
            [
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'email' => $this->email,
                'license_class' => $this->license_class,
                'preferred_timezone' => $this->preferred_timezone,
                'is_youth' => $this->is_youth,
                'notification_preferences' => [
                    'event_notifications' => $this->event_notifications,
                    'system_announcements' => $this->system_announcements,
                    'categories' => [
                        'new_section' => $this->notify_new_section,
                        'guestbook' => $this->notify_guestbook,
                        'photos' => $this->notify_photos,
                        'station_status' => $this->notify_station_status,
                        'qso_milestone' => $this->notify_qso_milestone,
                        'equipment' => $this->notify_equipment,
                        'bulletin_reminder' => $this->notify_bulletin_reminder,
                    ],
                ],
            ]
        );

        $this->success('Profile updated successfully.');
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
            && $this->notify_bulletin_reminder;
    }

    public function changePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            app(\Laravel\Fortify\Contracts\UpdatesUserPasswords::class)->update(
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

            $this->success('Password changed successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('current_password', 'The current password is incorrect.');
            $this->error('Password change failed.', 'The current password is incorrect.');
        }
    }

    public function logoutOtherSessions(): void
    {
        auth()->logoutOtherDevices($this->current_password);

        $this->current_password = '';

        $this->success('Logged out of all other devices.');
    }

    public function enableTwoFactor(): void
    {
        $this->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
        ]);

        app(EnableTwoFactorAuthentication::class)(auth()->user());

        auth()->user()->refresh();

        $this->showingQrCode = true;
        $this->showingRecoveryCodes = false;
        $this->current_password = '';
    }

    public function cancelTwoFactorSetup(): void
    {
        app(DisableTwoFactorAuthentication::class)(auth()->user());

        auth()->user()->refresh();

        $this->showingQrCode = false;
        $this->twoFactorCode = '';
    }

    public function confirmTwoFactor(): void
    {
        $this->validate([
            'twoFactorCode' => ['required', 'string'],
        ]);

        app(ConfirmTwoFactorAuthentication::class)(auth()->user(), $this->twoFactorCode);

        auth()->user()->refresh();

        $this->showingQrCode = false;
        $this->showingRecoveryCodes = true;
        $this->twoFactorCode = '';

        $this->success('Two-factor authentication enabled.');
    }

    public function disableTwoFactor(): void
    {
        $this->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
        ]);

        app(DisableTwoFactorAuthentication::class)(auth()->user());

        auth()->user()->refresh();

        $this->showingQrCode = false;
        $this->showingRecoveryCodes = false;
        $this->current_password = '';

        $this->success('Two-factor authentication disabled.');
    }

    public function showRecoveryCodes(): void
    {
        $this->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
        ]);

        $this->showingRecoveryCodes = true;
        $this->current_password = '';
    }

    public function regenerateRecoveryCodes(): void
    {
        app(GenerateNewRecoveryCodes::class)(auth()->user());

        auth()->user()->refresh();

        $this->showingRecoveryCodes = true;

        $this->success('Recovery codes regenerated.');
    }

    public function render()
    {
        $user = auth()->user();

        // Get login sessions
        $sessions = \DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderBy('last_activity', 'desc')
            ->get();

        // Get operating sessions (if OperatingSession model exists)
        $operatingSessions = collect(); // Placeholder - implement when OperatingSession model is ready

        // Get activity log
        $activityLog = collect(); // Placeholder - implement when audit logging is ready

        return view('livewire.profile.user-profile', [
            'user' => $user,
            'sessions' => $sessions,
            'operatingSessions' => $operatingSessions,
            'activityLog' => $activityLog,
        ])->layout('layouts.app');
    }
}
