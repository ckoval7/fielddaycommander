<?php

namespace App\Livewire\Profile;

use Livewire\Component;

class UserProfile extends Component
{
    public string $activeTab = 'profile';

    // Profile tab properties
    public string $call_sign = '';

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public ?string $license_class = null;

    public ?string $preferred_timezone = null;

    public bool $event_notifications = true;

    public bool $system_announcements = true;

    // Security tab properties
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = auth()->user();

        // Load profile data
        $this->call_sign = $user->call_sign;
        $this->first_name = $user->first_name;
        $this->last_name = $user->last_name;
        $this->email = $user->email;
        $this->license_class = $user->license_class;
        $this->preferred_timezone = $user->preferred_timezone ?? config('app.timezone');

        // Load notification preferences
        $preferences = $user->notification_preferences ?? [];
        $this->event_notifications = $preferences['event_notifications'] ?? true;
        $this->system_announcements = $preferences['system_announcements'] ?? true;
    }

    public function saveProfile(): void
    {
        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'license_class' => ['nullable', 'string', 'in:Technician,General,Advanced,Extra'],
            'preferred_timezone' => ['nullable', 'string', 'timezone:all'],
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
                'notification_preferences' => [
                    'event_notifications' => $this->event_notifications,
                    'system_announcements' => $this->system_announcements,
                ],
            ]
        );

        $this->dispatch('toast', type: 'success', message: 'Profile updated successfully.');
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

            $this->dispatch('toast', type: 'success', message: 'Password changed successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('current_password', 'The current password is incorrect.');
        }
    }

    public function logoutOtherSessions(): void
    {
        auth()->logoutOtherDevices($this->current_password);

        $this->current_password = '';

        $this->dispatch('toast', type: 'success', message: 'Logged out of all other devices.');
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
        ]);
    }
}
