<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demo Analytics</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
</head>
<body class="min-h-screen bg-base-200 p-6" x-data>

<div class="max-w-7xl mx-auto">
    {{-- Header --}}
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Demo Analytics</h1>
        <div class="flex items-center gap-2">
            @foreach(['today' => 'Today', '7d' => '7 Days', '30d' => '30 Days', '90d' => '90 Days'] as $key => $label)
                <a href="{{ $rangeLinks[$key] }}"
                   class="btn btn-sm {{ $range === $key ? 'btn-primary' : 'btn-ghost' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    {{-- Overview Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
        <div class="stat bg-base-100 rounded-box shadow-sm p-4">
            <div class="stat-title text-xs">Total Sessions</div>
            <div class="stat-value text-2xl">{{ $overview['total_sessions'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-box shadow-sm p-4">
            <div class="stat-title text-xs">Unique Visitors</div>
            <div class="stat-value text-2xl">{{ $overview['unique_visitors'] }}</div>
        </div>
        <div class="stat bg-base-100 rounded-box shadow-sm p-4">
            <div class="stat-title text-xs">Repeat Rate</div>
            <div class="stat-value text-2xl">{{ $overview['repeat_visitor_rate'] }}%</div>
        </div>
        <div class="stat bg-base-100 rounded-box shadow-sm p-4">
            <div class="stat-title text-xs">Avg Duration</div>
            <div class="stat-value text-2xl">{{ $overview['avg_duration_minutes'] }}m</div>
        </div>
        <div class="stat bg-base-100 rounded-box shadow-sm p-4">
            <div class="stat-title text-xs">Bounce Rate</div>
            <div class="stat-value text-2xl">{{ $overview['bounce_rate'] }}%</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        {{-- Role Distribution Chart --}}
        <div class="bg-base-100 rounded-box shadow-sm p-6" x-data="{
            init() {
                const data = @js($roleDistribution);
                if (!data.labels.length) return;
                new Chart(this.$refs.roleCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            data: data.data,
                            backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444'],
                        }]
                    },
                    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
                });
            }
        }">
            <h3 class="font-semibold mb-4">Role Distribution</h3>
            <canvas x-ref="roleCanvas" height="200"></canvas>
            @if(empty($roleDistribution['labels']))
                <p class="text-base-content/50 text-center py-8">No data yet</p>
            @endif
        </div>

        {{-- Session Funnel --}}
        <div class="bg-base-100 rounded-box shadow-sm p-6">
            <h3 class="font-semibold mb-4">Session Funnel</h3>
            @forelse($sessionFunnel as $step)
                <div class="mb-3">
                    <div class="flex justify-between text-sm mb-1">
                        <span>{{ $step['label'] }}</span>
                        <span class="text-base-content/60">{{ $step['count'] }} ({{ $step['pct'] }}%)</span>
                    </div>
                    <div class="w-full bg-base-200 rounded-full h-3">
                        <div class="bg-primary rounded-full h-3 transition-all duration-300"
                             style="width: {{ $step['pct'] }}%"></div>
                    </div>
                </div>
            @empty
                <p class="text-base-content/50 text-center py-8">No data yet</p>
            @endforelse
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        {{-- Page Popularity --}}
        <div class="bg-base-100 rounded-box shadow-sm p-6">
            <h3 class="font-semibold mb-4">Page Popularity</h3>
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Route</th>
                            <th class="text-right">Views</th>
                            <th class="text-right">Sessions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pagePopularity as $page)
                            <tr>
                                <td class="font-mono text-xs">{{ $page->route_name ?? '(unnamed)' }}</td>
                                <td class="text-right">{{ $page->views }}</td>
                                <td class="text-right">{{ $page->unique_sessions }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-base-content/50">No data yet</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Feature Engagement --}}
        <div class="bg-base-100 rounded-box shadow-sm p-6">
            <h3 class="font-semibold mb-4">Feature Engagement</h3>
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th class="text-right">Count</th>
                            <th class="text-right">Sessions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($featureEngagement as $feature)
                            <tr>
                                <td class="font-mono text-xs">{{ $feature->name }}</td>
                                <td class="text-right">{{ $feature->count }}</td>
                                <td class="text-right">{{ $feature->unique_sessions }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-base-content/50">No data yet</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        {{-- Time on Page --}}
        <div class="bg-base-100 rounded-box shadow-sm p-6">
            <h3 class="font-semibold mb-4">Time on Page (Top 10)</h3>
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Page</th>
                            <th class="text-right">Avg Time</th>
                            <th class="text-right">Views</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($timeOnPage as $page)
                            <tr>
                                <td class="font-mono text-xs">{{ $page->page }}</td>
                                <td class="text-right">{{ round($page->avg_seconds) }}s</td>
                                <td class="text-right">{{ $page->views }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-base-content/50">No data yet</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Repeat Visitors --}}
        <div class="bg-base-100 rounded-box shadow-sm p-6">
            <h3 class="font-semibold mb-4">Repeat Visitors</h3>
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Sessions</th>
                            <th>Roles Tried</th>
                            <th>Device</th>
                            <th>First Visit</th>
                            <th>Last Visit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($repeatVisitors as $visitor)
                            <tr>
                                <td>{{ $visitor['sessions_count'] }}</td>
                                <td class="text-xs">{{ implode(', ', $visitor['roles_tried']) }}</td>
                                <td>{{ $visitor['device_type'] }}</td>
                                <td class="text-xs">{{ \Carbon\Carbon::parse($visitor['first_visit'])->format('M j, g:ia') }}</td>
                                <td class="text-xs">{{ \Carbon\Carbon::parse($visitor['last_visit'])->format('M j, g:ia') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-base-content/50">No repeat visitors yet</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Recent Sessions --}}
    <div class="bg-base-100 rounded-box shadow-sm p-6">
        <h3 class="font-semibold mb-4">Recent Sessions</h3>
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th class="text-right">Pages</th>
                        <th class="text-right">Actions</th>
                        <th>Duration</th>
                        <th>Device</th>
                        <th>Referrer</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentSessions as $session)
                        <tr>
                            <td>
                                <span class="badge badge-sm badge-outline">
                                    {{ str_replace('_', ' ', $session->role) }}
                                </span>
                            </td>
                            <td class="text-right">{{ $session->total_page_views }}</td>
                            <td class="text-right">{{ $session->total_actions }}</td>
                            <td>{{ $session->last_seen_at->diffForHumans($session->provisioned_at, true) }}</td>
                            <td>{{ $session->device_type }}</td>
                            <td class="text-xs max-w-[150px] truncate">{{ $session->referrer ?? '—' }}</td>
                            <td class="text-xs">{{ $session->provisioned_at->format('M j, g:ia') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-base-content/50">No sessions yet</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
