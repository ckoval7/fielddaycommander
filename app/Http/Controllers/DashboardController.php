<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use App\Models\Event;
use App\Services\EventContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the main dashboard for authenticated users.
     *
     * Shows the get-ready view for upcoming events, the widget dashboard
     * for active/grace-period events, or the no-event view when no events exist.
     */
    public function index(EventContextService $eventContext): View
    {
        $user = Auth::user();
        $event = $eventContext->getContextEvent();

        // If there's a context event that hasn't started yet, show get-ready
        if ($event && $event->start_time && $event->start_time->isFuture()) {
            return $this->getReadyView($event);
        }

        // No context event at all — check for upcoming events
        if (! $event) {
            $upcoming = Event::upcoming()->orderBy('start_time')->first();

            if ($upcoming) {
                return $this->getReadyView($upcoming);
            }

            // No events at all — show widget dashboard (existing behavior)
        }

        // Active or grace-period event (or no event fallback) — show widget dashboard
        // Get or create user's default dashboard
        $dashboard = Dashboard::query()
            ->forUser($user)
            ->default()
            ->first();

        // If user doesn't have a default dashboard, create one
        if (! $dashboard) {
            $defaultConfig = config('dashboard.default_dashboards.user');

            $dashboard = Dashboard::create([
                'user_id' => $user->id,
                'title' => $defaultConfig['title'],
                'description' => $defaultConfig['description'],
                'layout_type' => $defaultConfig['layout_type'],
                'config' => $defaultConfig['widgets'],
                'is_default' => true,
            ]);
        }

        return view('dashboard.default', [
            'dashboard' => $dashboard,
            'widgets' => collect($dashboard->config),
        ]);
    }

    /**
     * Build and return the get-ready view for an upcoming event.
     */
    protected function getReadyView(Event $event): View
    {
        $event->load('eventConfiguration');

        $checklist = [
            [
                'label' => 'Event configuration created',
                'done' => $event->eventConfiguration !== null,
                'route' => $event->eventConfiguration
                    ? route('events.show', $event)
                    : route('events.show', $event),
            ],
            [
                'label' => 'Stations set up',
                'done' => $event->eventConfiguration?->stations()->exists() ?? false,
                'route' => route('stations.index'),
            ],
            [
                'label' => 'Equipment ready',
                'done' => false,
                'route' => route('events.equipment.dashboard', $event),
            ],
        ];

        return view('dashboard.get-ready', [
            'event' => $event,
            'checklist' => $checklist,
        ]);
    }

    /**
     * Display the public TV dashboard.
     *
     * Loads static TV dashboard configuration from config file.
     * Handles optional kiosk mode via query parameter.
     */
    public function tv(Request $request): View
    {
        $tvConfig = config('dashboard.default_dashboards.tv');

        // Check if kiosk mode is requested
        $kioskMode = $request->boolean('kiosk', false);

        return view('dashboard.tv', [
            'title' => $tvConfig['title'],
            'description' => $tvConfig['description'],
            'layout_type' => $tvConfig['layout_type'],
            'widgets' => collect($tvConfig['widgets']),
            'kiosk' => $kioskMode,
        ]);
    }
}
