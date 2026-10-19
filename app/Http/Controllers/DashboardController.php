<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\BulletinScheduleEntry;
use App\Models\Dashboard;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Services\DashboardService;
use App\Services\EventContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the main dashboard for authenticated users,
     * or the public landing page for unauthenticated visitors.
     *
     * Shows the get-ready view for upcoming events, the widget dashboard
     * for active/grace-period events, or the no-event view when no events exist.
     */
    public function index(Request $request, EventContextService $eventContext, DashboardService $dashboardService): View
    {
        if (! Auth::check()) {
            return view('public-landing');
        }

        $event = $eventContext->getContextEvent();

        // Active or grace-period event — show the widget dashboard
        if ($event && ! $event->start_time?->isAfter(appNow())) {
            return $this->widgetDashboardView($request, $dashboardService);
        }

        return $this->preEventView($event);
    }

    /**
     * Show the get-ready view for a context event that hasn't started yet,
     * or for the next upcoming event, falling back to the no-event view.
     */
    protected function preEventView(?Event $event): View
    {
        $upcoming = $event ?? Event::upcoming()->orderBy('start_time')->first();

        return $upcoming
            ? $this->getReadyView($upcoming)
            : view('dashboard-no-event', ['upcomingEvents' => collect()]);
    }

    /**
     * Show the widget dashboard, loading a specific dashboard if requested
     * and falling back to the user's default.
     */
    protected function widgetDashboardView(Request $request, DashboardService $dashboardService): View
    {
        $user = Auth::user();

        $requestedId = $request->integer('dashboard');
        $dashboard = $requestedId
            ? Dashboard::query()->forUser($user)->find($requestedId)
            : null;

        $dashboard ??= $dashboardService->getDefaultDashboard($user);

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
                'done' => EquipmentEvent::forEvent($event->id)->byOwner($user->id)->exists(),
                'route' => route('equipment.index'),
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

        if ($event->isScored()) {
            $checklist[] = [
                'label' => "{$event->nomenclature()->bulletinName} schedule set up",
                'done' => BulletinScheduleEntry::forEvent($event->id)->exists(),
                'route' => route('events.w1aw-bulletin'),
            ];
        }

        return view('dashboard.get-ready', [
            'event' => $event,
            'checklist' => $checklist,
        ]);
    }

    /**
     * Display the public landing page (always, regardless of auth state).
     */
    public function publicLanding(): View
    {
        return view('public-landing');
    }

    /**
     * Display the public TV dashboard.
     *
     * Loads the static TV dashboard configuration from the config file and
     * sizes a fixed grid from the visible widgets' column/row spans so the
     * whole board fits one screen. Handles optional kiosk mode via ?kiosk=1.
     */
    public function tv(Request $request): View
    {
        $tvConfig = config('dashboard.default_dashboards.tv');
        $columns = max(1, (int) ($tvConfig['columns'] ?? 5));

        $isScored = app(EventContextService::class)->getContextEvent()?->isScored() ?? true;

        $widgets = collect($tvConfig['widgets'])
            ->filter(fn (array $widget): bool => $widget['visible'] ?? true)
            ->reject(fn (array $widget): bool => ! $isScored && app(DashboardService::class)->isScoredOnly($widget))
            ->sortBy('order')
            ->map(fn (array $widget): array => [
                ...$widget,
                'col_span' => $this->tvSpan($widget, 'col_span', $columns),
                'row_span' => $this->tvSpan($widget, 'row_span'),
            ])
            ->values();

        $occupiedCells = $widgets->sum(fn (array $widget): int => $widget['col_span'] * $widget['row_span']);

        return view('dashboard.tv', [
            'title' => $tvConfig['title'],
            'description' => $tvConfig['description'],
            'layout_type' => $tvConfig['layout_type'],
            'widgets' => $widgets,
            'columns' => $columns,
            'rows' => max(1, (int) ceil($occupiedCells / $columns)),
            'kiosk' => $request->boolean('kiosk', false),
            'kioskShortcut' => strtolower((string) config('dashboard.kiosk.shortcut', 'f')),
            'showKioskHint' => (bool) config('dashboard.kiosk.show_exit_hint', true),
        ]);
    }

    /**
     * Resolve a TV widget's column or row span, clamped to at least 1 (and to
     * the grid width for columns).
     *
     * @param  array<string, mixed>  $widget
     */
    protected function tvSpan(array $widget, string $key, ?int $max = null): int
    {
        $span = max(1, (int) ($widget[$key] ?? 1));

        return $max === null ? $span : min($span, $max);
    }
}
