<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Try Field Day Commander</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-base-200 flex items-center justify-center p-6" x-data="provisioner()">

{{-- Loading overlay --}}
<div x-show="loading" x-cloak
     class="fixed inset-0 bg-base-100 flex flex-col items-center justify-center z-50 gap-6">
    <span class="loading loading-spinner loading-lg text-primary"></span>
    <div class="text-center">
        <p class="font-semibold text-lg" x-text="statusLine"></p>
        <p class="text-base-content/50 text-sm mt-1">This takes about 10–15 seconds</p>
    </div>
</div>

<div class="max-w-lg w-full" x-show="!loading">
    <div class="text-center mb-8">
        <h1 class="text-3xl font-bold">Field Day Commander</h1>
        <p class="text-base-content/60 mt-2">Pick a role to explore. Your sandbox is private and resets after 24 hours.</p>
    </div>

    @if(session('error'))
        <div class="alert alert-error mb-6">{{ session('error') }}</div>
    @endif

    <div class="grid gap-4">
        @foreach([
            ['role' => 'operator',        'label' => 'Operator',        'desc' => 'Log contacts and view the live dashboard', 'icon' => 'o-pencil-square'],
            ['role' => 'station_captain', 'label' => 'Station Captain', 'desc' => 'Manage a station, assign operators, log contacts', 'icon' => 'o-server-stack'],
            ['role' => 'event_manager',   'label' => 'Event Manager',   'desc' => 'Full event control — scoring, bonuses, schedule', 'icon' => 'o-trophy'],
            ['role' => 'system_admin',    'label' => 'System Admin',    'desc' => 'Everything, including settings and user management', 'icon' => 'o-cog-6-tooth'],
        ] as $option)
        <form method="POST" action="{{ route('demo.provision') }}" @submit="start">
            @csrf
            <input type="hidden" name="role" value="{{ $option['role'] }}">
            <button type="submit" class="btn btn-outline w-full justify-start gap-4 h-auto py-4" :disabled="loading">
                <x-icon name="{{ $option['icon'] }}" class="w-6 h-6 shrink-0" />
                <div class="text-left">
                    <div class="font-semibold">{{ $option['label'] }}</div>
                    <div class="text-sm text-base-content/60">{{ $option['desc'] }}</div>
                </div>
            </button>
        </form>
        @endforeach
    </div>

    <p class="text-center text-xs text-base-content/40 mt-8">
        Demo data is isolated per visitor. Nothing you do affects anyone else.
    </p>
</div>

<script>
function provisioner() {
    const steps = [
        'Provisioning your sandbox\u2026',
        'Running database migrations\u2026',
        'Seeding demo event data\u2026',
        'Logging in\u2026 almost there',
    ];
    return {
        loading: false,
        statusLine: steps[0],
        start() {
            this.loading = true;
            let i = 0;
            const advance = () => {
                if (i < steps.length - 1) {
                    i++;
                    this.statusLine = steps[i];
                    setTimeout(advance, 4000);
                }
            };
            setTimeout(advance, 2000);
        },
    };
}
</script>
</body>
</html>
