<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Interest;

use App\Enums\InterestAvailability;
use App\Enums\LicenseClass;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventInterest;
use App\Models\User;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Public form where people say they plan to participate in the next event, before
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

    /**
     * Minutes the registration page keeps offering those details, so the next
     * person on a shared browser doesn't get them.
     */
    public const PREFILL_MINUTES = 15;

    #[Locked]
    public ?int $eventId = null;

    public string $firstName = '';

    public string $lastName = '';

    public string $callsign = '';

    public string $email = '';

    public string $licenseClass = '';

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

        $this->firstName = $user->first_name ?? '';
        $this->lastName = $user->last_name ?? '';
        $this->callsign = $user->call_sign ?? '';
        $this->email = $user->email ?? '';
        $this->licenseClass = $user->license_class ?? '';

        $existing = $this->event?->eventConfiguration
            ?->interests()
            ->where(fn ($query) => $query->where('user_id', $user->id)
                ->when($user->hasVerifiedEmail(), fn ($query) => $query->orWhere('email', mb_strtolower($user->email))))
            ->first();

        if ($existing) {
            $this->callsign = $existing->callsign ?? $this->callsign;
            $this->licenseClass = $existing->license_class?->value ?? $this->licenseClass;
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
     * Whether to invite a visitor who isn't signed in to create an account.
     * It doesn't check the email, so the page never reveals who has one.
     */
    #[Computed]
    public function canRegister(): bool
    {
        return ! auth()->check()
            && config('auth-security.registration_mode') !== 'disabled';
    }

    public function save(): void
    {
        if ($this->honeypot !== '') {
            $this->submitted = true;

            return;
        }

        $config = $this->event?->eventConfiguration;

        if (! $config?->interest_form_enabled || $this->event->end_time->lt(appNow())) {
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

        // A signed-in user's response always goes under their account's email
        $email = mb_strtolower(trim(auth()->user()?->email ?? $validated['email']));
        $callsign = $validated['callsign'] !== '' ? mb_strtoupper(trim($validated['callsign'])) : null;

        $attributes = [
            'first_name' => trim($validated['firstName']),
            'last_name' => trim($validated['lastName']),
            'callsign' => $callsign,
            'license_class' => $validated['licenseClass'] ?: null,
            'availability' => $validated['availability'],
            'notes' => $validated['notes'] !== '' ? $validated['notes'] : null,
            'ip_address' => request()->ip(),
        ];

        try {
            $interest = $this->storeResponse($config->id, $email, $attributes);
        } catch (UniqueConstraintViolationException) {
            // A second tap saved the same response first; update that one instead
            $interest = $this->storeResponse($config->id, $email, $attributes);
        }

        if (! $interest) {
            return;
        }

        AuditLog::log(
            action: $this->updatedExisting ? 'interest.response.updated' : 'interest.response.sent',
            auditable: $interest,
            newValues: [
                'name' => $interest->full_name,
                'callsign' => $interest->callsign,
                'availability' => $validated['availability'],
            ],
        );

        if (! auth()->check()) {
            RateLimiter::hit($rateLimitKey, 3600);
            $this->rememberForRegistration($interest);
        }

        $this->email = $email;
        $this->submitted = true;
    }

    /**
     * Create or update the response for this email, or for the signed-in
     * user's account. Returns null, with an error added, when this person
     * may not change it.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function storeResponse(int $configId, string $email, array $attributes): ?EventInterest
    {
        $user = auth()->user();

        $interest = ($user ? EventInterest::where('event_configuration_id', $configId)->where('user_id', $user->id)->first() : null)
            ?? EventInterest::firstOrNew(['event_configuration_id' => $configId, 'email' => $email]);

        // A response tied to an account can only be changed by that account
        if ($interest->user_id !== null && $interest->user_id !== $user?->id) {
            $this->addError('form', 'We already have a response for this email. Sign in to change it.');

            return null;
        }

        // Only someone who has proved they own the email can take over its response
        if ($user && $interest->exists && $interest->user_id === null && ! $user->hasVerifiedEmail()) {
            $this->addError('form', 'Verify your email address to change the response sent under it.');

            return null;
        }

        $this->updatedExisting = $interest->exists;

        $interest->fill([...$attributes, 'user_id' => $user?->id ?? $interest->user_id])->save();

        return $interest;
    }

    /**
     * The details to pre-fill on the registration page, if the visitor sent
     * the form recently enough on this browser.
     *
     * @return array<string, string|null>
     */
    public static function registrationPrefill(): array
    {
        $prefill = session(self::PREFILL_SESSION_KEY, []);

        if (! isset($prefill['saved_at']) || $prefill['saved_at'] < now()->subMinutes(self::PREFILL_MINUTES)->getTimestamp()) {
            session()->forget(self::PREFILL_SESSION_KEY);

            return [];
        }

        return $prefill;
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
        session()->put(self::PREFILL_SESSION_KEY, [
            'first_name' => $interest->first_name,
            'last_name' => $interest->last_name,
            'call_sign' => $interest->callsign ?? '',
            'email' => $interest->email,
            'license_class' => $interest->license_class?->userValue(),
            'saved_at' => now()->getTimestamp(),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'callsign' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\/]*$/'],
            'email' => ['required', 'email', 'max:255'],
            'licenseClass' => ['nullable', Rule::enum(LicenseClass::class)],
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
            'firstName.required' => 'Please enter your first name.',
            'lastName.required' => 'Please enter your last name.',
            'email.required' => 'Please enter your email.',
            'callsign.regex' => 'A call sign can only have letters, numbers, and a slash.',
            'availability.required' => 'Pick at least one time you might be free.',
            'availability.min' => 'Pick at least one time you might be free.',
        ];
    }

    public function render(): View
    {
        return view('livewire.interest.interest-form')->layout('layouts.app', ['title' => 'Participation Interest']);
    }
}
