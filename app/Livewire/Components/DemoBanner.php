<?php

namespace App\Livewire\Components;

use App\Models\DemoSession;
use App\Models\Setting;
use App\Support\DemoCookie;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class DemoBanner extends Component
{
    public bool $isVisible = false;

    public ?Carbon $expiresAt = null;

    public ?string $inviteUrl = null;

    public int $participantCount = 1;

    public int $maxParticipants = 1;

    public bool $isFull = false;

    public function mount(): void
    {
        if (! config('demo.enabled')) {
            return;
        }

        $provisionedAt = Setting::get('demo_provisioned_at');

        if (! $provisionedAt) {
            return;
        }

        $this->expiresAt = Carbon::parse($provisionedAt)->addHours(config('demo.ttl_hours', 24));
        $this->isVisible = true;

        $this->loadInvite();
    }

    /**
     * Prepare the link this visitor can share to bring others into their sandbox.
     */
    private function loadInvite(): void
    {
        $demoSession = $this->visitorDemoSession();

        if ($demoSession === null) {
            return;
        }

        $this->inviteUrl = route('demo.join', $demoSession->ensureInviteToken());
        $this->participantCount = $demoSession->participant_count;
        $this->maxParticipants = DemoSession::maxParticipants();
        $this->isFull = $demoSession->isFull();
    }

    /**
     * Give the sandbox a new invite link so the one shared earlier stops
     * admitting people. Anyone in the sandbox may do this.
     */
    public function rotateInvite(): void
    {
        $demoSession = $this->visitorDemoSession();

        if ($demoSession === null) {
            return;
        }

        $this->inviteUrl = route('demo.join', $demoSession->rotateInviteToken());
    }

    private function visitorDemoSession(): ?DemoSession
    {
        if (! DemoSession::invitesEnabled()) {
            return null;
        }

        $uuid = DemoCookie::fromRequest(request())?->uuid;

        return $uuid === null ? null : DemoSession::where('session_uuid', $uuid)->first();
    }

    public function render(): View
    {
        return view('livewire.components.demo-banner');
    }
}
