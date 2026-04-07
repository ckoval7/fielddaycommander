<?php

namespace App\Listeners;

use App\Events\ContactLogged;
use App\Models\DemoEvent;
use App\Models\DemoSession;
use App\Models\EventBonus;
use App\Models\OperatingSession;
use App\Models\ShiftAssignment;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;

class DemoEventSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(ContactLogged::class, [$this, 'handleContactLogged']);
        $events->listen('eloquent.created: '.OperatingSession::class, [$this, 'handleSessionStarted']);
        $events->listen('eloquent.created: '.EventBonus::class, [$this, 'handleBonusClaimed']);
        $events->listen('eloquent.created: '.ShiftAssignment::class, [$this, 'handleShiftAssigned']);
    }

    public function handleContactLogged(ContactLogged $event): void
    {
        $session = $this->resolveSession();
        if (! $session) {
            return;
        }

        $contact = $event->contact;

        $this->recordAction($session, 'contact.logged', [
            'band' => $contact->band?->name,
            'mode' => $contact->mode?->name,
            'callsign' => $contact->callsign,
        ]);
    }

    public function handleSessionStarted(OperatingSession $operatingSession): void
    {
        $session = $this->resolveSession();
        if (! $session) {
            return;
        }

        $this->recordAction($session, 'session.started', [
            'station_name' => $operatingSession->station?->name,
            'band' => $operatingSession->band?->name,
            'mode' => $operatingSession->mode?->name,
        ]);
    }

    public function handleBonusClaimed(EventBonus $bonus): void
    {
        $session = $this->resolveSession();
        if (! $session) {
            return;
        }

        $this->recordAction($session, 'bonus.claimed', [
            'bonus_type' => $bonus->bonusType?->name,
        ]);
    }

    public function handleShiftAssigned(ShiftAssignment $assignment): void
    {
        $session = $this->resolveSession();
        if (! $session) {
            return;
        }

        $this->recordAction($session, 'shift.assigned', [
            'role' => $assignment->shift?->shiftRole?->name,
        ]);
    }

    private function resolveSession(): ?DemoSession
    {
        if (! config('demo.enabled')) {
            return null;
        }

        $cookie = request()->cookie('demo_session');
        [$uuid] = array_pad(explode('|', $cookie ?? '', 2), 2, null);

        if (! $uuid || ! Str::isUuid($uuid)) {
            return null;
        }

        return DemoSession::where('session_uuid', $uuid)->first();
    }

    private function recordAction(DemoSession $session, string $name, array $metadata = []): void
    {
        DemoEvent::create([
            'demo_session_id' => $session->id,
            'type' => 'action',
            'name' => $name,
            'metadata' => $metadata,
        ]);

        $session->increment('total_actions');
    }
}
