<?php

namespace App\Livewire\Guestbook;

use App\Enums\PresenceType;
use App\Enums\VisitorCategory;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class GuestbookForm extends Component
{
    /**
     * Visitor's name (first and last combined for simplicity).
     */
    public string $name = '';

    /**
     * Visitor's amateur radio callsign (optional).
     */
    public string $callsign = '';

    /**
     * Visitor's email address (optional).
     */
    public string $email = '';

    /**
     * Visitor's comments (optional).
     */
    public string $comments = '';

    /**
     * Presence type: 'in_person' or 'online'.
     */
    public string $presence_type = PresenceType::Online->value;

    /**
     * Visitor category for classification.
     */
    public string $visitor_category = VisitorCategory::GeneralPublic->value;

    /**
     * Honeypot field for bot detection (must remain empty).
     */
    public string $honeypot = '';

    /**
     * Whether to show the success message.
     */
    public bool $showSuccess = false;

    /**
     * The active event configuration.
     */
    public ?EventConfiguration $eventConfig = null;

    /**
     * Mount the component and initialize form data.
     */
    public function mount(): void
    {
        // Get the active event's configuration (if guestbook is enabled)
        $activeEvent = app(EventContextService::class)->getContextEvent();
        $config = $activeEvent?->eventConfiguration;
        $this->eventConfig = ($config && $config->guestbook_enabled) ? $config : null;

        // Pre-fill form if user is authenticated
        if (auth()->check()) {
            $user = auth()->user();
            $this->name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
            $this->callsign = $user->call_sign ?? '';
            $this->email = $user->email ?? '';
        }
    }

    /**
     * Get the event location data for geolocation detection.
     *
     * @return array{latitude: float|null, longitude: float|null, radius: int}
     */
    #[Computed]
    public function eventLocation(): array
    {
        return [
            'latitude' => $this->eventConfig?->latitude,
            'longitude' => $this->eventConfig?->longitude,
            'radius' => $this->eventConfig?->guestbook_detection_radius ?? 500,
        ];
    }

    /**
     * Check if event has location configured for geolocation detection.
     */
    #[Computed]
    public function hasEventLocation(): bool
    {
        return $this->eventConfig?->latitude !== null
            && $this->eventConfig?->longitude !== null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function visitorCategories(): array
    {
        return VisitorCategory::options();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    #[Computed]
    public function presenceTypes(): array
    {
        return PresenceType::options();
    }

    /**
     * Update presence type from Alpine.js geolocation detection.
     */
    public function setPresenceType(string $type): void
    {
        if (PresenceType::tryFrom($type)) {
            $this->presence_type = $type;
        }
    }

    /**
     * Save the guestbook entry.
     */
    public function save(): void
    {
        // Check honeypot - reject if filled (likely a bot)
        if (! empty($this->honeypot)) {
            // Silently fail for bots - don't give them feedback
            $this->showSuccess = true;

            return;
        }

        // Check if guestbook is enabled
        if (! $this->eventConfig || ! $this->eventConfig->guestbook_enabled) {
            $this->addError('form', 'The guestbook is currently not accepting entries.');

            return;
        }

        // Check rate limit (5 entries per IP per hour)
        $rateLimitKey = 'guestbook-entry:'.request()->ip();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $minutes = ceil($seconds / 60);

            throw ValidationException::withMessages([
                'form' => "Too many entries. Please try again in {$minutes} minute(s).",
            ]);
        }

        // Validate the form
        $validated = $this->validate();

        // Parse the name into first and last
        $nameParts = $this->parseFullName($validated['name']);

        // Create the guestbook entry
        $entry = GuestbookEntry::create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => auth()->id(),
            'first_name' => $nameParts['first_name'],
            'last_name' => $nameParts['last_name'],
            'callsign' => ! empty($validated['callsign']) ? strtoupper($validated['callsign']) : null,
            'email' => $validated['email'] ?: null,
            'comments' => $validated['comments'] ?: null,
            'presence_type' => $validated['presence_type'],
            'visitor_category' => $validated['visitor_category'],
            'ip_address' => request()->ip(),
            'is_verified' => false,
        ]);

        AuditLog::log(
            action: 'guestbook.entry.signed',
            auditable: $entry,
            newValues: [
                'name' => trim("{$entry->first_name} {$entry->last_name}"),
                'callsign' => $entry->callsign,
                'presence_type' => $entry->presence_type,
                'visitor_category' => $entry->visitor_category,
            ],
        );

        // Hit the rate limiter
        RateLimiter::hit($rateLimitKey, 3600); // 1 hour decay

        // Show success message
        $this->showSuccess = true;

        // Reset the form for another entry
        $this->resetFormFields();

        // Emit event for GuestbookList to refresh
        $this->dispatch('guestbook-entry-created');
    }

    /**
     * Reset the form to allow another entry.
     */
    public function resetFormFields(): void
    {
        // Keep name/callsign/email if logged in user, otherwise reset
        if (! auth()->check()) {
            $this->name = '';
            $this->callsign = '';
            $this->email = '';
        }

        $this->comments = '';
        $this->visitor_category = VisitorCategory::GeneralPublic->value;
        $this->honeypot = '';
    }

    /**
     * Hide the success message and allow another submission.
     */
    public function hideSuccess(): void
    {
        $this->showSuccess = false;
    }

    /**
     * Parse a full name into first and last name parts.
     *
     * @return array{first_name: string, last_name: string}
     */
    protected function parseFullName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2);

        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
        ];
    }

    /**
     * Get the validation rules.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'callsign' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'comments' => ['nullable', 'string', 'max:500'],
            'presence_type' => ['required', Rule::enum(PresenceType::class)],
            'visitor_category' => ['required', Rule::enum(VisitorCategory::class)],
            'honeypot' => ['max:0'],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'Please enter your name.',
            'presence_type.required' => 'Please select how you are visiting.',
            'visitor_category.required' => 'Please select a visitor category.',
            'honeypot.max' => 'Form submission failed.',
        ];
    }

    /**
     * Render the component.
     */
    public function render(): View
    {
        return view('livewire.guestbook.guestbook-form');
    }
}
