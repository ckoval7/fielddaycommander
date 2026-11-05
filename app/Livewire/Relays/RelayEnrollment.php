<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Relays;

use App\Models\Relay;
use App\Services\EventContextService;
use App\Services\RelayEnrollmentService;
use App\Support\RelayConnection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lets people enroll a Field Day Commander Relay for the current event and
 * revoke the relays they're allowed to manage.
 */
class RelayEnrollment extends Component
{
    public string $name = '';

    #[Locked]
    public ?string $issuedCode = null;

    #[Locked]
    public ?int $issuedRelayId = null;

    #[Locked]
    public string $serverUrl = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Relay::class);
        $this->serverUrl = request()->getSchemeAndHttpHost();
    }

    public function issue(RelayEnrollmentService $enrollment, EventContextService $events): void
    {
        $this->authorize('create', Relay::class);

        $validated = $this->validate(['name' => ['required', 'string', 'max:50']]);

        // Checked on each request: the code travels back in this response.
        if (! app(RelayConnection::class)->isAllowed(request())) {
            $this->addError('name', 'Open this page over HTTPS to enroll a relay.');

            return;
        }

        $config = $events->getEventConfiguration();

        if ($config?->event === null) {
            $this->addError('name', 'No current event is available for enrollment.');

            return;
        }

        $endsAt = $config->event->operatingEndsAt();

        if ($endsAt !== null && $endsAt->isPast()) {
            $this->addError('name', 'This event has ended. You can\'t create an enrollment code.');

            return;
        }

        $this->deleteOwnExpiredCodes();

        ['relay' => $relay, 'code' => $code] = $enrollment->issue($config, trim($validated['name']), Auth::id());

        $this->issuedCode = $code;
        $this->issuedRelayId = $relay->id;
        $this->reset('name');
    }

    /**
     * Polled while a code is showing, so it goes away once used or expired.
     */
    public function checkIssuedCode(): void
    {
        if ($this->issuedRelayId === null) {
            return;
        }

        $relay = Relay::find($this->issuedRelayId);

        if ($relay === null || $relay->status() !== Relay::STATUS_WAITING) {
            $this->dismissCode();
        }
    }

    public function dismissCode(): void
    {
        $this->reset('issuedCode', 'issuedRelayId');
    }

    public function revoke(int $relayId, RelayEnrollmentService $enrollment): void
    {
        $relay = Relay::active()->findOrFail($relayId);

        $this->authorize('revoke', $relay);

        $enrollment->revoke($relay, Auth::id());

        if ($this->issuedRelayId === $relay->id) {
            $this->dismissCode();
        }
    }

    public function render(EventContextService $events): View
    {
        $config = $events->getEventConfiguration();

        return view('livewire.relays.relay-enrollment', [
            'isSecure' => app(RelayConnection::class)->isAllowed(request()),
            'eventName' => $config?->event?->name,
            'canCreateStations' => auth()->user()->can('manage-stations'),
            'relays' => $config === null ? new Collection : $this->visibleRelays($config->id),
        ])->layout('components.layouts.app');
    }

    /**
     * @return Collection<int, Relay>
     */
    private function visibleRelays(int $eventConfigurationId): Collection
    {
        return Relay::query()
            ->where('event_configuration_id', $eventConfigurationId)
            ->when(
                ! Auth::user()->can('viewAll', Relay::class),
                fn ($query) => $query->where('created_by_user_id', Auth::id()),
            )
            ->with('createdBy')
            ->latest('id')
            ->limit(100)
            ->get()
            ->reject(fn (Relay $relay): bool => $relay->status() === Relay::STATUS_EXPIRED)
            ->values();
    }

    /**
     * An abandoned code has nothing worth keeping. The audit log still has it.
     */
    private function deleteOwnExpiredCodes(): void
    {
        Relay::query()
            ->where('created_by_user_id', Auth::id())
            ->whereNull('token_hash')
            ->whereNull('revoked_at')
            ->where('enrollment_expires_at', '<=', now())
            ->delete();
    }
}
