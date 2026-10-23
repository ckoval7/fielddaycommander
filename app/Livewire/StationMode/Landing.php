<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\StationMode;

use App\Models\AuditLog;
use App\Models\StationDevice;
use App\Models\User;
use App\Services\UserResolverService;
use App\Support\StationMode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Landing extends Component
{
    public string $callSign = '';

    public string $firstName = '';

    public string $lastName = '';

    public bool $needsName = false;

    public bool $isYouth = false;

    public bool $showExitModal = false;

    public string $exitEmail = '';

    public string $exitPassword = '';

    public function mount(): void
    {
        if (StationMode::isActive()) {
            $this->redirectRoute('logging.station-select');

            return;
        }

        if (! $this->device) {
            $this->redirectRoute(Auth::check() ? 'dashboard' : 'login');

            return;
        }

        if (Auth::check() && ! $this->canExitAsCurrentUser) {
            $this->redirectRoute('dashboard');
        }
    }

    #[Computed]
    public function device(): ?StationDevice
    {
        return StationMode::deviceFromCookie(request());
    }

    /**
     * Whether a captain already signed in on this browser can exit station mode without re-entering credentials.
     */
    #[Computed]
    public function canExitAsCurrentUser(): bool
    {
        return Auth::check() && Auth::user()->can('manage-stations');
    }

    /**
     * Reset the name prompt when the callsign changes.
     */
    public function updatedCallSign(): void
    {
        $this->needsName = false;
        $this->isYouth = false;
        $this->resetValidation();
    }

    /**
     * Sign the walk-up operator in by callsign, creating a ghost account if needed.
     */
    public function start(UserResolverService $resolver): void
    {
        $device = $this->device;

        if (! $device) {
            $this->redirectRoute('login');

            return;
        }

        $this->callSign = strtoupper(trim($this->callSign));

        $this->validate([
            'callSign' => ['required', 'string', 'max:12', 'regex:/^[A-Z0-9\/]+$/'],
        ], [
            'callSign.regex' => 'Enter a callsign using letters, numbers, and "/" only.',
        ], [
            'callSign' => 'callsign',
        ]);

        $existing = User::withTrashed()->where('call_sign', $this->callSign)->first();

        if ($existing && ($existing->trashed() || $existing->isSystemUser())) {
            $this->addError('callSign', 'This callsign cannot be used for station mode. See a station captain.');

            return;
        }

        if ($existing && ! $existing->is_ghost && $existing->isLocked()) {
            $this->addError('callSign', 'This account is locked. See an administrator.');

            return;
        }

        if (! $existing || $resolver->hasPlaceholderName($existing)) {
            if (! $this->needsName) {
                $this->needsName = true;

                return;
            }

            $this->validate([
                'firstName' => ['required', 'string', 'max:50'],
                'lastName' => ['required', 'string', 'max:50'],
            ], attributes: [
                'firstName' => 'first name',
                'lastName' => 'last name',
            ]);
        }

        $user = $existing && ! $resolver->hasPlaceholderName($existing)
            ? $existing
            : $resolver->resolveOrCreate($this->callSign, $this->firstName, $this->lastName);

        // Only the name step for a new ghost asks the age question, so the flag
        // is never set on (or cleared from) a member signing in.
        if ($this->needsName && $this->isYouth && $user->is_ghost && ! $user->is_youth) {
            $user->update(['is_youth' => true]);
        }

        StationMode::startSession($device, $user->id);

        $this->redirectRoute('logging.station-select');
    }

    public function openExitModal(): void
    {
        $this->resetValidation();
        $this->exitEmail = '';
        $this->exitPassword = '';
        $this->showExitModal = true;
    }

    public function cancelExit(): void
    {
        $this->showExitModal = false;
        $this->exitPassword = '';
        $this->resetValidation();
    }

    /**
     * Take this browser out of station mode after a station captain confirms with their credentials.
     */
    public function exitStationMode(): void
    {
        $device = $this->device;

        if (! $device) {
            $this->redirectRoute('login');

            return;
        }

        if ($this->canExitAsCurrentUser) {
            $this->revokeAndForget($device, Auth::id());

            return;
        }

        $this->validate([
            'exitEmail' => ['required', 'email'],
            'exitPassword' => ['required', 'string'],
        ], attributes: [
            'exitEmail' => 'email',
            'exitPassword' => 'password',
        ]);

        $rateLimitKey = 'station-mode-exit:'.request()->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $this->addError('exitEmail', 'Too many attempts. Try again in '.RateLimiter::availableIn($rateLimitKey).' seconds.');

            return;
        }

        $captain = User::where('email', strtolower($this->exitEmail))->first();

        if (! $captain
            || $captain->isLocked()
            || ! Hash::check($this->exitPassword, $captain->password)
            || ! $captain->checkPermissionTo('manage-stations')
        ) {
            RateLimiter::hit($rateLimitKey);
            $this->addError('exitEmail', 'Check the email and password. Use an unlocked account with permission to manage stations.');

            return;
        }

        RateLimiter::clear($rateLimitKey);

        $this->revokeAndForget($device, $captain->id);
    }

    private function revokeAndForget(StationDevice $device, int $userId): void
    {
        $device->update(['revoked_at' => now()]);
        StationMode::forgetCookie();

        AuditLog::log(
            action: 'station.device_exited',
            userId: $userId,
            auditable: $device->station,
            newValues: ['device_id' => $device->id],
        );

        $this->redirectRoute(Auth::check() ? 'stations.index' : 'login');
    }

    public function render(): View
    {
        return view('livewire.station-mode.landing')
            ->layout('components.layouts.guest');
    }
}
