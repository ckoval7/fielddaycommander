<?php

namespace App\Livewire\Admin;

use App\Models\DemoEvent;
use App\Models\DemoSession;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DemoAnalytics extends Component
{
    use AuthorizesRequests;

    public string $dateRange = '7d';

    public function mount(): void
    {
        abort_unless(config('demo.enabled'), 404);

        // DEBUG: temporary - remove after diagnosing 403
        if (! auth()->check()) {
            abort(403, 'DEBUG: No authenticated user');
        }
        $user = auth()->user();
        $roles = $user->roles->pluck('name')->join(', ');
        $perms = $user->getAllPermissions()->pluck('name')->join(', ');
        $can = $user->can('manage-settings');
        logger("DemoAnalytics DEBUG: user={$user->email} roles=[{$roles}] perms=[{$perms}] can={$can}");
        abort_unless($can, 403, "DEBUG: user={$user->email} roles=[{$roles}] can=".($can ? 'yes' : 'no'));
    }

    #[Computed]
    public function startDate(): Carbon
    {
        return match ($this->dateRange) {
            'today' => now()->startOfDay(),
            '7d' => now()->subDays(7)->startOfDay(),
            '30d' => now()->subDays(30)->startOfDay(),
            '90d' => now()->subDays(90)->startOfDay(),
            default => now()->subDays(7)->startOfDay(),
        };
    }

    #[Computed]
    public function sessions(): Collection
    {
        return DemoSession::where('provisioned_at', '>=', $this->startDate)
            ->orderByDesc('provisioned_at')
            ->get();
    }

    #[Computed]
    public function totalSessions(): int
    {
        return $this->sessions->count();
    }

    #[Computed]
    public function uniqueVisitors(): int
    {
        return $this->sessions->unique('visitor_hash')->count();
    }

    #[Computed]
    public function repeatVisitorRate(): float
    {
        if ($this->uniqueVisitors === 0) {
            return 0;
        }

        $repeatCount = $this->sessions
            ->groupBy('visitor_hash')
            ->filter(fn ($group) => $group->count() > 1)
            ->count();

        return round(($repeatCount / $this->uniqueVisitors) * 100, 1);
    }

    #[Computed]
    public function averageDurationMinutes(): float
    {
        if ($this->totalSessions === 0) {
            return 0;
        }

        $totalMinutes = $this->sessions->sum(
            fn ($s) => $s->last_seen_at->diffInMinutes($s->provisioned_at)
        );

        return round($totalMinutes / $this->totalSessions, 1);
    }

    #[Computed]
    public function bounceRate(): float
    {
        if ($this->totalSessions === 0) {
            return 0;
        }

        $bounces = $this->sessions->filter(fn ($s) => $s->total_page_views <= 2)->count();

        return round(($bounces / $this->totalSessions) * 100, 1);
    }

    #[Computed]
    public function roleDistribution(): array
    {
        $counts = $this->sessions->groupBy('role')->map->count()->sortDesc();
        $labels = $counts->keys()->map(fn ($r) => str_replace('_', ' ', ucfirst($r)))->values()->toArray();
        $data = $counts->values()->toArray();

        return ['labels' => $labels, 'data' => $data];
    }

    #[Computed]
    public function pagePopularity(): Collection
    {
        $sessionIds = $this->sessions->pluck('id');

        return DemoEvent::whereIn('demo_session_id', $sessionIds)
            ->where('type', 'page_view')
            ->selectRaw('route_name, COUNT(*) as views, COUNT(DISTINCT demo_session_id) as unique_sessions')
            ->groupBy('route_name')
            ->orderByDesc('views')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function featureEngagement(): Collection
    {
        $sessionIds = $this->sessions->pluck('id');

        return DemoEvent::whereIn('demo_session_id', $sessionIds)
            ->where('type', 'action')
            ->selectRaw('name, COUNT(*) as count, COUNT(DISTINCT demo_session_id) as unique_sessions')
            ->groupBy('name')
            ->orderByDesc('count')
            ->get();
    }

    #[Computed]
    public function sessionFunnel(): array
    {
        $total = $this->totalSessions;
        if ($total === 0) {
            return [];
        }

        $sessionIds = $this->sessions->pluck('id');

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

        $explored3Pages = $this->sessions->filter(fn ($s) => $s->total_page_views >= 3)->count();

        $active10Min = $this->sessions->filter(
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

    #[Computed]
    public function timeOnPage(): Collection
    {
        if (! in_array(config('database.default'), ['mysql', 'mariadb'])) {
            return collect();
        }

        $sessionIds = $this->sessions->pluck('id');

        return DemoEvent::whereIn('demo_session_id', $sessionIds)
            ->where('type', 'client')
            ->where('name', 'time_on_page')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.page')) as page, AVG(JSON_EXTRACT(metadata, '$.seconds')) as avg_seconds, COUNT(*) as views")
            ->groupByRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.page'))")
            ->orderByDesc('avg_seconds')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function repeatVisitors(): Collection
    {
        return $this->sessions
            ->groupBy('visitor_hash')
            ->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => [
                'sessions_count' => $group->count(),
                'roles_tried' => $group->pluck('role')->unique()->values()->toArray(),
                'first_visit' => $group->min('provisioned_at'),
                'last_visit' => $group->max('provisioned_at'),
                'device_type' => $group->first()->device_type,
            ])
            ->sortByDesc('sessions_count')
            ->values();
    }

    public function render(): View
    {
        return view('livewire.admin.demo-analytics')
            ->layout('layouts.app');
    }
}
