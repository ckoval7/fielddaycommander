<?php

namespace App\Livewire\Components;

use App\Models\Setting;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class EventContextBanner extends Component
{
    public function returnToActive(): void
    {
        $service = app(EventContextService::class);
        $service->clearViewingEvent();

        $this->redirect(request()->header('Referer', '/'), navigate: true);
    }

    public function render(): View
    {
        $service = app(EventContextService::class);
        $contextEvent = $service->getContextEvent();
        $activeEvent = $service->getActiveEvent();
        $isViewingPast = $service->isViewingPastEvent();
        $gracePeriodStatus = $contextEvent ? $service->getGracePeriodStatus($contextEvent) : null;

        // Calculate grace days remaining if in grace period
        $graceDaysRemaining = null;
        if ($gracePeriodStatus === 'grace' && $contextEvent?->end_time) {
            $graceDays = (int) Setting::get('post_event_grace_period_days', 30);
            $graceEnd = $contextEvent->end_time->copy()->addDays($graceDays);
            $graceDaysRemaining = (int) appNow()->diffInDays($graceEnd, false);
        }

        return view('livewire.components.event-context-banner', [
            'contextEvent' => $contextEvent,
            'activeEvent' => $activeEvent,
            'isViewingPast' => $isViewingPast,
            'gracePeriodStatus' => $gracePeriodStatus,
            'graceDaysRemaining' => $graceDaysRemaining,
        ]);
    }
}
