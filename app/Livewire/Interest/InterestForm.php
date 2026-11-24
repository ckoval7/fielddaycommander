<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Interest;

use App\Enums\InterestAvailability;
use App\Enums\LicenseClass;
use App\Models\Event;
use App\Models\EventInterest;
use App\Models\User;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Public form where people say they'd like to help at the next event, before
 * shift sign-ups are open. Sending it again with the same email updates the
 * earlier response.
 */
class InterestForm extends Component
{
    /**
     * Responses per IP per hour.
     */
    public const RATE_LIMIT = 5;

    /**
     * Session key holding the details to pre-fill on the registration page.
     */
    public const PREFILL_SESSION_KEY = 'interest_prefill';

    #[Locked]
    public ?int $eventId = null;

    public string $name = '';

    public string $callsign = '';

    public string $email = '';

    public string $license_class = '';

    /** @var list<string> */
    public array $availability = [];

    public string $notes = '';

    /**
     * Honeypot field for bot detection (must remain empty).
     */
    public string $honeypot = '';

    public bool $submitted = false;

    /**
     * Whether the response updated one sent earlier.
     */
    public bool $updatedExisting = false;

    public function mount(): void
    {
        $this->eventId = app(EventContextService::class)->getOpenInterestEvent()?->id;

        $user = auth()->user();

        if (! $user) {
            return;
        }

        $this->name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
        $this->callsign = $user->call_sign ?? '';
        $this->email = $user->email ?? '';
        $this->license_class = $user->license_class ?? '';

        $existing = $this->event?->eventConfiguration
            ?->interests()
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhere('email', $user->email))
            ->first();

        if ($existing) {
            $this->callsign = $existing->callsign ?? $this->callsign;
            $this->license_class = $existing->license_class?->value ?? $this->license_class;
            $this->availability = $existing->availability->map(fn (InterestAvailability $block): string => $block->value)->all();
            $this->notes = $existing->notes ?? '';
        }
    }

    #[Computed]
    public function event(): ?Event
    {
        return $this->eventId
            ? Event::with('eventConfiguration')->find($this->eventId)
            : null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function licenseClasses(): array
    {
        return LicenseClass::options();
    }

    /**
     * @return list<InterestAvailability>
     */
    #[Computed]
    public function availabilityOptions(): array
    {
        return InterestAvailability::cases();
    }

    /**
     * Whether to invite a visitor without an account to create one.
     */
    #[Computed]
    public function canRegister(): bool
    {
        return ! auth()->check()
            && config('auth-security.registration_mode') !== 'disabled'
            && ! User::where('email', $this->email)->exists();
    }

    /**
     * Whether a visitor who isn't signed in already has an account under this email.
     */
    #[Computed]
    public function hasAccount(): bool
    {
        return ! auth()->check() && User::where('email', $this->email)->exists();
    }

    public function save(): void
    {
        if ($this->honeypot !== '') {
            $this->submitted = true;

            return;
        }

        $config = $this->event?->eventConfiguration;

        if (! $config?->interest_form_enabled || $this->event->end_time->isPast()) {
            $this->addError('form', 'This form is closed.');

            return;
        }

        $rateLimitKey = 'event-interest:'.request()->ip();

        if (! auth()->check() && RateLimiter::tooManyAttempts($rateLimitKey, self::RATE_LIMIT)) {
            $minutes = (int) ceil(RateLimiter::availableIn($rateLimitKey) / 60);

            throw ValidationException::withMessages([
                'form' => "Too many responses from this network. Please try again in {$minutes} ".str('minute')->plural($minutes).'.',
            ]);
        }

        $validated = $this->validate();

        $email = mb_strtolower(trim($validated['email']));
        $callsign = $validated['callsign'] !== '' ? mb_strtoupper(trim($validated['callsign'])) : null;

        $interest = EventInterest::firstOrNew([
            'event_configuration_id' => $config->id,
            'email' => $email,
        ]);

        // A response tied to an account can only be changed by that account
        if ($interest->user_id !== null && $interest->user_id !== auth()->id()) {
            $this->addError('form', 'We already have a response for this email. Sign in to change it.');

            return;
        }

        $this->updatedExisting = $interest->exists;

        $interest->fill([
            'user_id' => auth()->id() ?? $interest->user_id,
            'name' => trim($validated['name']),
            'callsign' => $callsign,
            'license_class' => $validated['license_class'] ?: null,
            'availability' => $validated['availability'],
            'notes' => $validated['notes'] !== '' ? $validated['notes'] : null,
            'ip_address' => request()->ip(),
        ])->save();

        if (! auth()->check()) {
            RateLimiter::hit($rateLimitKey, 3600);
            $this->rememberForRegistration($interest);
        }

        $this->email = $email;
        $this->submitted = true;
    }

    /**
     * Let the visitor go back and change what they sent.
     */
    public function editResponse(): void
    {
        $this->submitted = false;
    }

    /**
     * Keep the visitor's details in the session so the registration page
     * can start with them filled in.
     */
    protected function rememberForRegistration(EventInterest $interest): void
    {
        $parts = preg_split('/\s+/', $interest->name, 2);

        session()->put(self::PREFILL_SESSION_KEY, [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
            'call_sign' => $interest->callsign ?? '',
            'email' => $interest->email,
            'license_class' => $interest->license_class?->userValue(),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'callsign' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\/]*$/'],
            'email' => ['required', 'email', 'max:255'],
            'license_class' => ['nullable', Rule::enum(LicenseClass::class)],
            'availability' => ['required', 'array', 'min:1'],
            'availability.*' => [Rule::enum(InterestAvailability::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'honeypot' => ['max:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'Please enter your name.',
            'email.required' => 'Please enter your email.',
            'callsign.regex' => 'A call sign can only have letters, numbers, and a slash.',
            'availability.required' => 'Pick at least one time you might be able to help.',
            'availability.min' => 'Pick at least one time you might be able to help.',
        ];
    }

    public function render(): View
    {
        return view('livewire.interest.interest-form')->layout('layouts.app', ['title' => 'Volunteer Interest']);
    }
}
