<?php

namespace App\Livewire\Components;

use App\Models\DemoSession;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

class DemoBanner extends Component
{
    public bool $isVisible = false;

    public ?Carbon $expiresAt = null;

    public ?string $inviteUrl = null;

    public int $participantCount = 1;

    public int $maxParticipants = 1;

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
        if (! DemoSession::invitesEnabled()) {
            return;
        }

        [$uuid] = explode('|', (string) request()->cookie('demo_session'), 2);

        if (! Str::isUuid($uuid)) {
            return;
        }

        $demoSession = DemoSession::where('session_uuid', $uuid)->first();

        if ($demoSession === null) {
            return;
        }

        $this->inviteUrl = route('demo.join', $demoSession->ensureInviteToken());
        $this->participantCount = $demoSession->participant_count;
        $this->maxParticipants = DemoSession::maxParticipants();
    }

    public function render(): View
    {
        return view('livewire.components.demo-banner');
    }
}
