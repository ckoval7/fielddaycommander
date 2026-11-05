<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Agents;

use App\Models\Agent;
use App\Services\AgentEnrollmentService;
use App\Services\EventContextService;
use App\Support\AgentConnection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lets people enroll a Field Day Commander Agent for the current event and
 * revoke the agents they're allowed to manage.
 */
class AgentEnrollment extends Component
{
    public string $name = '';

    #[Locked]
    public ?string $issuedCode = null;

    #[Locked]
    public ?int $issuedAgentId = null;

    #[Locked]
    public string $serverUrl = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Agent::class);
        $this->serverUrl = request()->getSchemeAndHttpHost();
    }

    public function issue(AgentEnrollmentService $enrollment, EventContextService $events): void
    {
        $this->authorize('create', Agent::class);

        $validated = $this->validate(['name' => ['required', 'string', 'max:50']]);

        // Checked on each request: the code travels back in this response.
        if (! app(AgentConnection::class)->isAllowed(request())) {
            $this->addError('name', 'Open this page over HTTPS to enroll an agent.');

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

        ['agent' => $agent, 'code' => $code] = $enrollment->issue($config, trim($validated['name']), Auth::id());

        $this->issuedCode = $code;
        $this->issuedAgentId = $agent->id;
        $this->reset('name');
    }

    /**
     * Polled while a code is showing, so it goes away once used or expired.
     */
    public function checkIssuedCode(): void
    {
        if ($this->issuedAgentId === null) {
            return;
        }

        $agent = Agent::find($this->issuedAgentId);

        if ($agent === null || $agent->status() !== Agent::STATUS_WAITING) {
            $this->dismissCode();
        }
    }

    public function dismissCode(): void
    {
        $this->reset('issuedCode', 'issuedAgentId');
    }

    public function revoke(int $agentId, AgentEnrollmentService $enrollment): void
    {
        $agent = Agent::active()->findOrFail($agentId);

        $this->authorize('revoke', $agent);

        $enrollment->revoke($agent, Auth::id());

        if ($this->issuedAgentId === $agent->id) {
            $this->dismissCode();
        }
    }

    public function render(EventContextService $events): View
    {
        $config = $events->getEventConfiguration();

        return view('livewire.agents.agent-enrollment', [
            'isSecure' => app(AgentConnection::class)->isAllowed(request()),
            'eventName' => $config?->event?->name,
            'agents' => $config === null ? new Collection : $this->visibleAgents($config->id),
        ])->layout('components.layouts.app');
    }

    /**
     * @return Collection<int, Agent>
     */
    private function visibleAgents(int $eventConfigurationId): Collection
    {
        return Agent::query()
            ->where('event_configuration_id', $eventConfigurationId)
            ->when(
                ! Auth::user()->can('viewAll', Agent::class),
                fn ($query) => $query->where('created_by_user_id', Auth::id()),
            )
            ->with('createdBy')
            ->latest('id')
            ->limit(100)
            ->get()
            ->reject(fn (Agent $agent): bool => $agent->status() === Agent::STATUS_EXPIRED)
            ->values();
    }

    /**
     * An abandoned code has nothing worth keeping. The audit log still has it.
     */
    private function deleteOwnExpiredCodes(): void
    {
        Agent::query()
            ->where('created_by_user_id', Auth::id())
            ->whereNull('token_hash')
            ->whereNull('revoked_at')
            ->where('enrollment_expires_at', '<=', now())
            ->delete();
    }
}
