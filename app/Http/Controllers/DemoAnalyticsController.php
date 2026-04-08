<?php

namespace App\Http\Controllers;

use App\Models\DemoEvent;
use App\Models\DemoSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class DemoAnalyticsController extends Controller
{
    public function beacon(Request $request): Response
    {
        abort_unless(config('demo.enabled'), 404);

        $cookie = $request->cookie('demo_session');
        [$uuid] = array_pad(explode('|', $cookie ?? '', 2), 2, null);

        if (! $uuid || ! Str::isUuid($uuid)) {
            return response('', 302, ['Location' => route('demo.landing')]);
        }

        $validated = $request->validate([
            'page' => 'required|string|max:500',
            'seconds' => 'required|integer|min:0|max:86400',
            'route' => 'nullable|string|max:100',
        ]);

        $session = DemoSession::where('session_uuid', $uuid)->first();

        if (! $session) {
            return response()->noContent();
        }

        DemoEvent::create([
            'demo_session_id' => $session->id,
            'type' => 'client',
            'name' => 'time_on_page',
            'route_name' => $validated['route'] ?? null,
            'metadata' => [
                'seconds' => $validated['seconds'],
                'page' => $validated['page'],
            ],
        ]);

        return response()->noContent();
    }

    public function dashboard(Request $request): View
    {
        abort_unless(config('demo.enabled'), 404);

        [$range, $sessions, $sessionIds] = $this->resolveAnalyticsData($request);

        $rangeLinks = [];
        foreach (['today', '7d', '30d', '90d'] as $r) {
            $rangeLinks[$r] = URL::temporarySignedRoute(
                'demo.analytics.dashboard',
                now()->addHours(24),
                ['range' => $r]
            );
        }

        return view('demo.analytics', [
            'range' => $range,
            'rangeLinks' => $rangeLinks,
            'overview' => $this->buildOverview($sessions),
            'roleDistribution' => $this->buildRoleDistribution($sessions),
            'sessionFunnel' => $this->buildSessionFunnel($sessions, $sessionIds),
            'pagePopularity' => $this->buildPagePopularity($sessionIds),
            'featureEngagement' => $this->buildFeatureEngagement($sessionIds),
            'timeOnPage' => $this->buildTimeOnPage($sessionIds),
            'repeatVisitors' => $this->buildRepeatVisitors($sessions),
            'recentSessions' => $sessions->take(25),
        ]);
    }

    public function api(Request $request): JsonResponse
    {
        abort_unless(config('demo.enabled'), 404);

        [$range, $sessions, $sessionIds] = $this->resolveAnalyticsData($request);

        return response()->json([
            'range' => $range,
            'generated_at' => now()->toIso8601String(),
            'overview' => $this->buildOverview($sessions),
            'role_distribution' => $this->buildRoleDistribution($sessions),
            'session_funnel' => $this->buildSessionFunnel($sessions, $sessionIds),
            'page_popularity' => $this->buildPagePopularity($sessionIds),
            'feature_engagement' => $this->buildFeatureEngagement($sessionIds),
            'time_on_page' => $this->buildTimeOnPage($sessionIds),
            'repeat_visitors' => $this->buildRepeatVisitors($sessions),
            'recent_sessions' => $sessions->take(25)->map(fn ($s) => [
                'role' => $s->role,
                'total_page_views' => $s->total_page_views,
                'total_actions' => $s->total_actions,
                'duration_minutes' => round($s->last_seen_at->diffInMinutes($s->provisioned_at), 1),
                'device_type' => $s->device_type,
                'referrer' => $s->referrer,
                'provisioned_at' => $s->provisioned_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * @return array{string, Collection, Collection}
     */
    private function resolveAnalyticsData(Request $request): array
    {
        $range = $this->resolveRange($request->query('range'));
        $startDate = $this->startDateForRange($range);
        $sessions = $this->fetchSessions($startDate);
        $sessionIds = $sessions->pluck('id');

        return [$range, $sessions, $sessionIds];
    }

    private function resolveRange(?string $range): string
    {
        return in_array($range, ['today', '7d', '30d', '90d'], true) ? $range : '7d';
    }

    private function startDateForRange(string $range): Carbon
    {
        return match ($range) {
            'today' => now()->startOfDay(),
            '7d' => now()->subDays(7)->startOfDay(),
            '30d' => now()->subDays(30)->startOfDay(),
            '90d' => now()->subDays(90)->startOfDay(),
            default => now()->subDays(7)->startOfDay(),
        };
    }

    private function fetchSessions(Carbon $startDate): Collection
    {
        return DemoSession::where('provisioned_at', '>=', $startDate)
            ->orderByDesc('provisioned_at')
            ->get();
    }

    private function buildOverview(Collection $sessions): array
    {
        $total = $sessions->count();
        $uniqueVisitors = $sessions->unique('visitor_hash')->count();

        $repeatVisitorRate = 0.0;
        if ($uniqueVisitors > 0) {
            $repeatCount = $sessions->groupBy('visitor_hash')
                ->filter(fn ($group) => $group->count() > 1)
                ->count();
            $repeatVisitorRate = round(($repeatCount / $uniqueVisitors) * 100, 1);
        }

        $avgDuration = 0.0;
        if ($total > 0) {
            $totalMinutes = $sessions->sum(
                fn ($s) => $s->last_seen_at->diffInMinutes($s->provisioned_at)
            );
            $avgDuration = round($totalMinutes / $total, 1);
        }

        $bounceRate = 0.0;
        if ($total > 0) {
            $bounces = $sessions->filter(fn ($s) => $s->total_page_views <= 2)->count();
            $bounceRate = round(($bounces / $total) * 100, 1);
        }

        return [
            'total_sessions' => $total,
            'unique_visitors' => $uniqueVisitors,
            'repeat_visitor_rate' => $repeatVisitorRate,
            'avg_duration_minutes' => $avgDuration,
            'bounce_rate' => $bounceRate,
        ];
    }

    private function buildRoleDistribution(Collection $sessions): array
    {
        $counts = $sessions->groupBy('role')->map->count()->sortDesc();
        $labels = $counts->keys()->map(fn ($r) => ucwords(str_replace('_', ' ', $r)))->values()->toArray();
        $data = $counts->values()->toArray();

        return ['labels' => $labels, 'data' => $data];
    }

    private function buildSessionFunnel(Collection $sessions, Collection $sessionIds): array
    {
        $total = $sessions->count();
        if ($total === 0) {
            return [];
        }

        $viewedDashboard = DemoEvent::whereIn('demo_session_id', $sessionIds)
            ->where('type', 'page_view')
            ->where(function ($q) {
                $q->where('route_name', 'like', '%dashboard%')
                    ->orWhere('route_name', 'like', '%home%');
            })
            ->distinct('demo_session_id')
            ->count('demo_session_id');

        $loggedContact = DemoEvent::whereIn('demo_session_id', $sessionIds)
            ->where('name', 'contact.logged')
            ->distinct('demo_session_id')
            ->count('demo_session_id');

        $explored3Pages = $sessions->filter(fn ($s) => $s->total_page_views >= 3)->count();

        $active10Min = $sessions->filter(
            fn ($s) => $s->last_seen_at->diffInMinutes($s->provisioned_at) >= 10
        )->count();

        return [
            ['label' => 'Provisioned', 'count' => $total, 'pct' => 100],
            ['label' => 'Viewed Dashboard', 'count' => $viewedDashboard, 'pct' => round(($viewedDashboard / $total) * 100, 1)],
            ['label' => 'Logged a Contact', 'count' => $loggedContact, 'pct' => round(($loggedContact / $total) * 100, 1)],
            ['label' => 'Explored 3+ Pages', 'count' => $explored3Pages, 'pct' => round(($explored3Pages / $total) * 100, 1)],
            ['label' => 'Active 10+ Min', 'count' => $active10Min, 'pct' => round(($active10Min / $total) * 100, 1)],
        ];
    }

    private function buildPagePopularity(Collection $sessionIds): Collection
    {
        return DemoEvent::whereIn('demo_session_id', $sessionIds)
            ->where('type', 'page_view')
            ->selectRaw('route_name, COUNT(*) as views, COUNT(DISTINCT demo_session_id) as unique_sessions')
            ->groupBy('route_name')
            ->orderByDesc('views')
            ->limit(20)
            ->get();
    }

    private function buildFeatureEngagement(Collection $sessionIds): Collection
    {
        return DemoEvent::whereIn('demo_session_id', $sessionIds)
            ->where('type', 'action')
            ->selectRaw('name, COUNT(*) as count, COUNT(DISTINCT demo_session_id) as unique_sessions')
            ->groupBy('name')
            ->orderByDesc('count')
            ->get();
    }

    private function buildTimeOnPage(Collection $sessionIds): Collection
    {
        if (! in_array(config('database.default'), ['mysql', 'mariadb'])) {
            return collect();
        }

        return DemoEvent::whereIn('demo_session_id', $sessionIds)
            ->where('type', 'client')
            ->where('name', 'time_on_page')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.page')) as page, AVG(JSON_EXTRACT(metadata, '$.seconds')) as avg_seconds, COUNT(*) as views")
            ->groupByRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.page'))")
            ->orderByDesc('avg_seconds')
            ->limit(10)
            ->get();
    }

    private function buildRepeatVisitors(Collection $sessions): Collection
    {
        return $sessions
            ->groupBy('visitor_hash')
            ->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => [
                'sessions_count' => $group->count(),
                'roles_tried' => $group->pluck('role')->unique()->values()->toArray(),
                'first_visit' => $group->min('provisioned_at')->toIso8601String(),
                'last_visit' => $group->max('provisioned_at')->toIso8601String(),
                'device_type' => $group->first()->device_type,
            ])
            ->sortByDesc('sessions_count')
            ->values();
    }
}
