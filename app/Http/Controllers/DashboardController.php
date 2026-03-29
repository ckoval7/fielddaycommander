<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use App\Models\EquipmentEvent;
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
        if ($event && $event->start_time && $event->start_time->isAfter(appNow())) {
            return $this->getReadyView($event);
        }

        // No context event at all — check for upcoming events or show empty state
        if (! $event) {
            $upcoming = Event::upcoming()->orderBy('start_time')->first();

            if ($upcoming) {
                return $this->getReadyView($upcoming);
            }

            return view('dashboard-no-event', [
                'upcomingEvents' => collect(),
            ]);
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

        $eventConfig = $event->eventConfiguration;

        $user = Auth::user();

        $checklist = [
            [
                'label' => 'Event configuration created',
                'done' => $eventConfig !== null,
                'route' => $user->can('view-events') ? route('events.show', $event) : null,
            ],
            [
                'label' => 'Equipment inventoried',
                'done' => EquipmentEvent::where('event_id', $event->id)->exists(),
                'route' => ($user->can('manage-event-equipment') || $user->can('view-all-equipment'))
                    ? route('events.equipment.dashboard', $event)
                    : null,
            ],
            [
                'label' => 'Stations set up',
                'done' => $eventConfig?->stations()->exists() ?? false,
                'route' => $user->can('view-stations') ? route('stations.index') : null,
            ],
            [
                'label' => 'Shifts scheduled',
                'done' => $eventConfig?->shifts()->exists() ?? false,
                'route' => $user->can('manage-shifts') ? route('schedule.manage') : null,
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
