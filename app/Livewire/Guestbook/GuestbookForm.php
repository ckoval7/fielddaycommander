<?php

namespace App\Livewire\Guestbook;

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
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
    public string $presence_type = 'online';

    /**
     * Visitor category for classification.
     */
    public string $visitor_category = 'general_public';

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
        $activeEvent = app(\App\Services\EventContextService::class)->getContextEvent();
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
            'latitude' => $this->eventConfig?->guestbook_latitude,
            'longitude' => $this->eventConfig?->guestbook_longitude,
            'radius' => $this->eventConfig?->guestbook_detection_radius ?? 500,
        ];
    }

    /**
     * Check if event has location configured for geolocation detection.
     */
    #[Computed]
    public function hasEventLocation(): bool
    {
        return $this->eventConfig?->guestbook_latitude !== null
            && $this->eventConfig?->guestbook_longitude !== null;
    }

    /**
     * Get available visitor categories with labels.
     *
     * @return array<int, array{id: string, name: string}>
     */
    #[Computed]
    public function visitorCategories(): array
    {
        return [
            ['id' => GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC, 'name' => 'General Public'],
            ['id' => GuestbookEntry::VISITOR_CATEGORY_HAM_CLUB, 'name' => 'Ham Radio Club Member'],
            ['id' => GuestbookEntry::VISITOR_CATEGORY_YOUTH, 'name' => 'Youth Visitor'],
            ['id' => GuestbookEntry::VISITOR_CATEGORY_ARES_RACES, 'name' => 'ARES/RACES Member'],
            ['id' => GuestbookEntry::VISITOR_CATEGORY_MEDIA, 'name' => 'Media Representative'],
            ['id' => GuestbookEntry::VISITOR_CATEGORY_AGENCY, 'name' => 'Agency Representative'],
            ['id' => GuestbookEntry::VISITOR_CATEGORY_ARRL_OFFICIAL, 'name' => 'ARRL Official'],
            ['id' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL, 'name' => 'Elected Official'],
        ];
    }

    /**
     * Get presence type options.
     *
     * @return array<int, array{id: string, name: string}>
     */
    #[Computed]
    public function presenceTypes(): array
    {
        return [
            ['id' => GuestbookEntry::PRESENCE_TYPE_IN_PERSON, 'name' => 'In Person'],
            ['id' => GuestbookEntry::PRESENCE_TYPE_ONLINE, 'name' => 'Online'],
        ];
    }

    /**
     * Update presence type from Alpine.js geolocation detection.
     */
    public function setPresenceType(string $type): void
    {
        if (in_array($type, GuestbookEntry::PRESENCE_TYPES, true)) {
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

        // Block the SYSTEM account from signing the guestbook
        if (auth()->check() && auth()->user()->isSystemUser()) {
            $this->dispatch('toast', title: 'Error', description: 'The SYSTEM account cannot sign the guestbook.', icon: 'o-x-circle', css: 'alert-error');

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
        GuestbookEntry::create([
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
        $this->visitor_category = 'general_public';
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
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'callsign' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'comments' => ['nullable', 'string', 'max:500'],
            'presence_type' => ['required', 'in:'.implode(',', GuestbookEntry::PRESENCE_TYPES)],
            'visitor_category' => ['required', 'in:'.implode(',', GuestbookEntry::VISITOR_CATEGORIES)],
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
            'name.max' => 'Name must be 100 characters or less.',
            'callsign.max' => 'Callsign must be 20 characters or less.',
            'email.email' => 'Please enter a valid email address.',
            'comments.max' => 'Comments must be 500 characters or less.',
            'presence_type.required' => 'Please select how you are visiting.',
            'presence_type.in' => 'Invalid presence type selected.',
            'visitor_category.required' => 'Please select a visitor category.',
            'visitor_category.in' => 'Invalid visitor category selected.',
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
