<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="reverb-config" content="{{ json_encode(config('demo.enabled') ? [] : ['key' => config('reverb.apps.apps.0.key'), 'host' => config('reverb.apps.apps.0.options.host'), 'port' => config('reverb.apps.apps.0.options.port'), 'scheme' => config('reverb.apps.apps.0.options.scheme')]) }}">
    <title>{{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    {{-- Set theme before page renders to prevent flash. Re-apply after
         wire:navigate, which strips unknown attributes from <html>. --}}
    <script>
        (function() {
            function applyTheme() {
                let theme = localStorage.getItem('theme');
                if (!theme) {
                    theme = 'light';
                    localStorage.setItem('theme', theme);
                }
                document.documentElement.setAttribute('data-theme', theme);
            }
            applyTheme();
            document.addEventListener('livewire:navigated', applyTheme);
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans antialiased bg-base-200">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
        {{-- Logo/Brand --}}
        @php
            // Priority 1: System settings (from Settings page)
            $siteName = \App\Models\Setting::get('site_name');
            $siteTagline = \App\Models\Setting::get('site_tagline');
            $siteLogoPath = \App\Models\Setting::get('site_logo_path');

            // Priority 2: Get active event configuration
            $activeEvent = \App\Models\EventConfiguration::where('is_active', true)->first();

            // Set branding data with priority hierarchy
            // Logo: System settings > Event config > Default
            if ($siteLogoPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($siteLogoPath)) {
                $logoPath = \Illuminate\Support\Facades\Storage::url($siteLogoPath);
            } elseif ($activeEvent && $activeEvent->logo_path) {
                $logoPath = $activeEvent->logo_path;
            } else {
                $logoPath = config('branding.default_logo', '/images/logo.png');
            }

            // Site Name/Callsign: System settings > Event callsign > Default
            if ($siteName) {
                $callsign = $siteName;
            } elseif ($activeEvent) {
                $callsign = $activeEvent->callsign;
            } else {
                $callsign = config('branding.default_callsign', config('app.name'));
            }

            // Event Name: Active event > Site name > Default
            if ($activeEvent) {
                $eventName = $activeEvent->event->name ?? ($siteName ?: config('app.name'));
            } else {
                $eventName = $siteName ?: config('app.name');
            }

            // Tagline: System settings > Event tagline > Default
            if ($siteTagline) {
                $tagline = $siteTagline;
            } elseif ($activeEvent && $activeEvent->tagline) {
                $tagline = $activeEvent->tagline;
            } else {
                $tagline = config('branding.default_tagline');
            }
        @endphp
        <div class="mb-8">
            <a href="/" class="flex items-center gap-3">
                @if(file_exists(public_path($logoPath)))
                    <img src="{{ asset($logoPath) }}" alt="Logo" class="w-16 h-16 object-contain">
                @else
                    <div class="w-16 h-16 rounded-lg bg-primary flex items-center justify-center">
                        <x-icon name="phosphor-cell-signal-high" class="w-10 h-10 text-primary-content" />
                    </div>
                @endif
                <div class="text-left">
                    <div class="font-bold text-2xl">{{ $callsign }}</div>
                    @if($eventName !== $callsign)
                        <div class="text-sm opacity-60">{{ $eventName }}</div>
                    @endif
                </div>
            </a>
        </div>

        {{-- Content Card --}}
        <div class="w-full max-w-4xl">
            <div class="card bg-base-100 shadow-xl p-8">
                {{ $slot }}
            </div>
        </div>

        {{-- Theme Toggle --}}
        <div class="mt-6">
            <x-theme-toggle />
        </div>
    </div>

    <x-toast />
</body>
</html>
