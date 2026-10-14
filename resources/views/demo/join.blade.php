<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join a Field Day Commander Demo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-base-200 flex items-center justify-center p-6">

<div id="join-overlay" style="display:none"
     class="fixed inset-0 bg-base-100 flex flex-col items-center justify-center z-50 gap-6">
    <span class="loading loading-spinner loading-lg text-primary"></span>
    <p class="font-semibold text-lg">Joining the sandbox&hellip;</p>
</div>

<div id="join-content" class="max-w-lg w-full">
    <div class="text-center mb-8">
        <h1 class="text-3xl font-bold">Field Day Commander</h1>
        <p class="text-base-content/60 mt-2">
            You've been invited to a shared
            {{ $demoSession->event_type === 'WFD' ? 'Winter Field Day' : 'ARRL Field Day' }} demo.
            Everyone in it sees and changes the same data.
        </p>
        <p class="text-sm text-base-content/50 mt-2">
            {{ $demoSession->participant_count }} of {{ $maxParticipants }} spots taken
            · expires {{ $demoSession->expires_at->diffForHumans() }}
        </p>
    </div>

    @if(session('error'))
        <div class="alert alert-error mb-6">{{ session('error') }}</div>
    @endif

    @if($demoSession->isFull())
        <div class="alert alert-warning mb-6">
            This sandbox is full. Start your own demo instead.
        </div>

        <a href="{{ route('demo.landing') }}" class="btn btn-primary w-full">Start my own demo</a>
    @else
        <form method="POST" action="{{ route('demo.join.store', $token) }}" id="demo-join-form" class="grid gap-6">
            @csrf

            <div>
                <p class="text-sm font-semibold mb-2">1. Tell everyone who you are <span class="font-normal text-base-content/50">(optional)</span></p>
                <p class="text-xs text-base-content/60 mb-3">You'll get your own account in this sandbox, so others can see what you log. Leave these blank to use a made-up name and call sign.</p>
                @include('demo.partials.identity-fields')
            </div>

            <fieldset>
                <legend class="text-sm font-semibold mb-2">2. Choose a role</legend>
                @error('role') <p class="text-error text-xs mb-2">{{ $message }}</p> @enderror
                <div class="grid gap-3">
                    @foreach([
                        ['role' => 'operator',        'label' => 'Operator',        'desc' => 'Log contacts and view the live dashboard', 'icon' => 'o-pencil-square'],
                        ['role' => 'station_captain', 'label' => 'Station Captain', 'desc' => 'Manage a station, assign operators, log contacts', 'icon' => 'o-server-stack'],
                        ['role' => 'event_manager',   'label' => 'Event Manager',   'desc' => 'Full event control — scoring, bonuses, schedule', 'icon' => 'o-trophy'],
                        ['role' => 'system_admin',    'label' => 'System Admin',    'desc' => 'Everything, including settings and user management', 'icon' => 'o-cog-6-tooth'],
                    ] as $option)
                    <label class="flex items-center gap-4 rounded-box border border-base-300 bg-base-100 px-4 py-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                        <input type="radio" name="role" value="{{ $option['role'] }}" class="radio radio-primary radio-sm"
                               required @checked(old('role', 'operator') === $option['role'])>
                        <x-icon name="{{ $option['icon'] }}" class="w-6 h-6 shrink-0" />
                        <span>
                            <span class="block font-semibold">{{ $option['label'] }}</span>
                            <span class="block text-sm text-base-content/60">{{ $option['desc'] }}</span>
                        </span>
                    </label>
                    @endforeach
                </div>
            </fieldset>

            <button type="submit" class="btn btn-primary w-full">Join sandbox</button>
        </form>

        <p class="text-center text-sm mt-6">
            <a href="{{ route('demo.landing') }}" class="link">Start my own private demo instead</a>
        </p>
    @endif
</div>

<script>
var joinForm = document.getElementById('demo-join-form');

if (joinForm) {
    joinForm.addEventListener('submit', function () {
        document.getElementById('join-overlay').style.display = 'flex';
        document.getElementById('join-content').style.display = 'none';
    });
}
</script>
</body>
</html>
