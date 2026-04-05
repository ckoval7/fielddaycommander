@php
    // Branding — same priority logic as guest layout
    $siteName = \App\Models\Setting::get('site_name');
    $siteTagline = \App\Models\Setting::get('site_tagline');
    $siteLogoPath = \App\Models\Setting::get('site_logo_path');
    $welcomeMessage = \App\Models\Setting::get('site_welcome_message');

    $activeEvent = \App\Models\Event::active()->with('eventConfiguration')->first()
        ?? \App\Models\Event::inSetupWindow()->orderBy('start_time')->with('eventConfiguration')->first();

    $eventConfig = $activeEvent?->eventConfiguration;

    // Logo resolution
    if ($siteLogoPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($siteLogoPath)) {
        $logoUrl = \Illuminate\Support\Facades\Storage::url($siteLogoPath);
    } elseif ($eventConfig && $eventConfig->logo_path) {
        $logoUrl = $eventConfig->logo_path;
    } else {
        $logoUrl = config('branding.default_logo', '/images/logo.svg');
    }

    // Callsign / site name
    $callsign = $siteName ?: ($eventConfig?->callsign ?? config('app.name'));

    // Event name
    $eventName = $activeEvent?->name ?? ($siteName ?: config('app.name'));

    // Tagline
    $tagline = $siteTagline ?: ($eventConfig?->tagline ?? config('branding.default_tagline'));
@endphp

<x-layouts.app>
    <x-slot:title>Welcome</x-slot:title>

    <div class="p-4 sm:p-6 lg:p-8">
        {{-- Hero Section --}}
        <div class="text-center mb-10">
            {{-- Logo --}}
            <div class="flex justify-center mb-4">
                @if($logoUrl && file_exists(public_path($logoUrl)))
                    <img src="{{ asset($logoUrl) }}" alt="Logo" class="w-24 h-24 object-contain">
                @elseif($logoUrl && str_starts_with($logoUrl, '/storage/'))
                    <img src="{{ $logoUrl }}" alt="Logo" class="w-24 h-24 object-contain">
                @else
                    <div class="w-24 h-24 rounded-lg bg-primary flex items-center justify-center">
                        <x-icon name="o-signal" class="w-14 h-14 text-primary-content" />
                    </div>
                @endif
            </div>

            {{-- Callsign / Title --}}
            <h1 class="text-4xl sm:text-5xl font-bold mb-2">{{ $callsign }}</h1>

            @if($eventName && $eventName !== $callsign)
                <p class="text-xl sm:text-2xl text-base-content/70 mb-1">{{ $eventName }}</p>
            @endif

            @if($tagline)
                <p class="text-lg text-base-content/60 mb-4">{{ $tagline }}</p>
            @endif

            {{-- Event dates --}}
            @if($activeEvent && $activeEvent->start_time && $activeEvent->end_time)
                @php $tz = \App\Models\Setting::get('timezone', 'America/New_York'); @endphp
                <p class="text-base text-base-content/50 mb-4">
                    {{ $activeEvent->start_time->timezone($tz)->format('F j, Y g:i A') }} &mdash;
                    {{ $activeEvent->end_time->timezone($tz)->format('F j, Y g:i A T') }}
                </p>
            @endif

            {{-- Welcome message --}}
            @if($welcomeMessage)
                <div class="max-w-2xl mx-auto mt-4">
                    <p class="text-base-content/80 text-base leading-relaxed whitespace-pre-line">{{ $welcomeMessage }}</p>
                </div>
            @endif

            {{-- CTA --}}
            <div class="mt-6">
                @auth
                    <a href="{{ route('dashboard.alt') }}" class="btn btn-primary">
                        <x-icon name="o-home" class="w-5 h-5" />
                        Go to Dashboard
                    </a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-primary">
                        <x-icon name="o-arrow-right-end-on-rectangle" class="w-5 h-5" />
                        Sign In
                    </a>
                @endauth
            </div>
        </div>

        {{-- Live Stats Section --}}
        @if($activeEvent && $eventConfig)
            <div class="border-t border-base-300 pt-8">
                <h2 class="text-2xl font-bold text-center mb-6">Live Stats</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                    {{-- QSO Count --}}
                    <livewire:dashboard.widgets.stat-card
                        :config="['metric' => 'qso_count', 'show_trend' => false]"
                        widget-id="public-qso-count"
                        size="normal"
                    />

                    {{-- Total Score --}}
                    <livewire:dashboard.widgets.stat-card
                        :config="['metric' => 'total_score', 'show_trend' => false]"
                        widget-id="public-total-score"
                        size="normal"
                    />
                </div>

                {{-- Band/Mode Grid --}}
                <div class="mb-6">
                    <livewire:dashboard.widgets.band-mode-grid />
                </div>

                {{-- Sections Worked --}}
                <div>
                    <livewire:dashboard.widgets.sections-worked
                        :config="[]"
                        widget-id="public-sections-worked"
                        size="normal"
                    />
                </div>
            </div>
        @else
            <div class="border-t border-base-300 pt-8">
                <div class="text-center py-12">
                    <x-icon name="o-calendar" class="w-16 h-16 text-base-content/30 mx-auto mb-4" />
                    <p class="text-lg text-base-content/60">No active event at this time. Check back soon!</p>
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
