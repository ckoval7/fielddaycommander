<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Try Field Day Commander</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-base-200 flex items-center justify-center p-6">

{{-- Loading overlay (hidden until form submit) --}}
<div id="provision-overlay" style="display:none"
     class="fixed inset-0 bg-base-100 flex flex-col items-center justify-center z-50 gap-6">
    <span class="loading loading-spinner loading-lg text-primary"></span>
    <div class="text-center">
        <p id="provision-status" class="font-semibold text-lg">Provisioning your sandbox&hellip;</p>
        <p class="text-base-content/50 text-sm mt-1">This usually takes a few seconds</p>
    </div>
</div>

<div id="provision-content" class="max-w-lg w-full">
    <div class="text-center mb-8">
        <h1 class="text-3xl font-bold">Field Day Commander</h1>
        @php($ttlHours = config('demo.ttl_hours', 24))
        <p class="text-base-content/60 mt-2">Pick an event and a role to explore. Your sandbox is private and resets after {{ $ttlHours }} {{ \Illuminate\Support\Str::plural('hour', $ttlHours) }}.</p>
    </div>

    @if(session('error'))
        <div class="alert alert-error mb-6">{{ session('error') }}</div>
    @endif

    {{-- Every provisioning slot was busy: the script below resubmits the
         visitor's choice after a short pause instead of making them click again. --}}
    @if($retry = session('provision_retry'))
        <form id="provision-retry" method="POST" action="{{ route('demo.provision') }}" hidden>
            @csrf
            <input type="hidden" name="role" value="{{ $retry['role'] }}">
            <input type="hidden" name="event_type" value="{{ $retry['event_type'] }}">
            <input type="hidden" name="attempt" value="{{ $retry['attempt'] }}">
            <input type="hidden" name="first_name" value="{{ $retry['first_name'] ?? '' }}">
            <input type="hidden" name="last_name" value="{{ $retry['last_name'] ?? '' }}">
            <input type="hidden" name="call_sign" value="{{ $retry['call_sign'] ?? '' }}">
        </form>
    @endif

    {{-- Step 1: which rulebook the sandbox is built around. The choice is
         mirrored into the provision form's hidden input by the script below. --}}
    <div class="mb-6">
        <p class="text-sm font-semibold mb-2">1. Choose an event</p>
        <div class="grid grid-cols-2 gap-3" role="radiogroup" aria-label="Event">
            @foreach([
                ['code' => 'FD',  'label' => 'ARRL Field Day',   'desc' => 'June · classes A–F, GOTA, bonus points'],
                ['code' => 'WFD', 'label' => 'Winter Field Day', 'desc' => 'January · classes H/I/O/M, objective multipliers'],
            ] as $i => $event)
            <button type="button"
                    role="radio"
                    aria-checked="{{ $i === 0 ? 'true' : 'false' }}"
                    data-event-code="{{ $event['code'] }}"
                    class="demo-event-option btn h-auto py-3 flex-col items-start gap-1 text-left normal-case {{ $i === 0 ? 'btn-primary' : 'btn-outline' }}">
                <span class="font-semibold">{{ $event['label'] }}</span>
                <span class="text-xs font-normal opacity-70">{{ $event['desc'] }}</span>
            </button>
            @endforeach
        </div>
    </div>

    <form method="POST" action="{{ route('demo.provision') }}" id="demo-provision-form">
        @csrf
        <input type="hidden" name="event_type" value="FD" class="demo-event-input">

        <div class="mb-6">
            <p class="text-sm font-semibold mb-2">2. Tell us who you are <span class="font-normal text-base-content/50">(optional)</span></p>
            <p class="text-xs text-base-content/60 mb-3">Leave these blank to use a made-up name and call sign. If you invite others, this is how they'll see you.</p>
            @include('demo.partials.identity-fields')
        </div>

        <p class="text-sm font-semibold mb-2">3. Choose a role</p>
        @error('role') <p class="text-error text-xs mb-2">{{ $message }}</p> @enderror
        <div class="grid gap-4">
            @foreach([
                ['role' => 'operator',        'label' => 'Operator',        'desc' => 'Log contacts and view the live dashboard', 'icon' => 'o-pencil-square'],
                ['role' => 'station_captain', 'label' => 'Station Captain', 'desc' => 'Manage a station, assign operators, log contacts', 'icon' => 'o-server-stack'],
                ['role' => 'event_manager',   'label' => 'Event Manager',   'desc' => 'Full event control — scoring, bonuses, schedule', 'icon' => 'o-trophy'],
                ['role' => 'system_admin',    'label' => 'System Admin',    'desc' => 'Everything, including settings and user management', 'icon' => 'o-cog-6-tooth'],
            ] as $option)
            <button type="submit" name="role" value="{{ $option['role'] }}" class="btn btn-outline w-full justify-start gap-4 h-auto py-4">
                <x-icon name="{{ $option['icon'] }}" class="w-6 h-6 shrink-0" />
                <div class="text-left">
                    <div class="font-semibold">{{ $option['label'] }}</div>
                    <div class="text-sm text-base-content/60">{{ $option['desc'] }}</div>
                </div>
            </button>
            @endforeach
        </div>
    </form>

    <p class="text-center text-xs text-base-content/40 mt-8">
        Demo data is isolated per visitor. Nothing you do affects anyone else.
    </p>
</div>

<script>
(function () {
    var steps = [
        'Provisioning your sandbox\u2026',
        'Running database migrations\u2026',
        'Seeding demo event data\u2026',
        'Logging in\u2026 almost there'
    ];

    var overlay  = document.getElementById('provision-overlay');
    var content  = document.getElementById('provision-content');
    var statusEl = document.getElementById('provision-status');

    // Event picker: highlight the chosen option and mirror its code into the
    // hidden input on every role form, so whichever role button is pressed
    // submits the currently selected event.
    var eventOptions = Array.prototype.slice.call(
        document.querySelectorAll('.demo-event-option')
    );
    var eventInputs = document.querySelectorAll('.demo-event-input');

    function selectEvent(chosen) {
        eventOptions.forEach(function (option) {
            var isChosen = option === chosen;
            option.classList.toggle('btn-primary', isChosen);
            option.classList.toggle('btn-outline', !isChosen);
            option.setAttribute('aria-checked', isChosen ? 'true' : 'false');
        });

        eventInputs.forEach(function (input) {
            input.value = chosen.dataset.eventCode;
        });
    }

    eventOptions.forEach(function (option, index) {
        option.addEventListener('click', function () {
            selectEvent(option);
        });

        option.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') {
                return;
            }
            e.preventDefault();
            var delta = e.key === 'ArrowRight' ? 1 : -1;
            var next = eventOptions[(index + delta + eventOptions.length) % eventOptions.length];
            selectEvent(next);
            next.focus();
        });
    });

    var retryForm = document.getElementById('provision-retry');

    if (retryForm) {
        overlay.style.display = 'flex';
        content.style.display = 'none';
        statusEl.textContent  = 'Lots of visitors right now \u2014 waiting for a free spot\u2026';
        setTimeout(function () { retryForm.submit(); }, 3000);
    }

    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            overlay.style.display  = 'flex';
            content.style.display  = 'none';

            var i = 0;
            var advance = function () {
                if (i < steps.length - 1) {
                    i++;
                    statusEl.textContent = steps[i];
                    setTimeout(advance, 8000);
                }
            };
            setTimeout(advance, 5000);
        });
    });
}());
</script>
</body>
</html>
