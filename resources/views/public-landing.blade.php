{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
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
        $logoUrl = config('branding.default_logo', '/images/logo.png');
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

    <div class="p-4 sm:p-6 lg:p-8 max-sm:px-0">
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
                        <x-icon name="phosphor-cell-signal-high" class="w-14 h-14 text-primary-content" />
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

            {{-- Talk-in frequency --}}
            @if($eventConfig?->talk_in_frequency)
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 text-primary mb-4">
                    <x-icon name="phosphor-broadcast" class="w-5 h-5" />
                    <span class="text-sm font-medium">Talk-in: {{ $eventConfig->talk_in_frequency }}</span>
                </div>
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
                        <x-icon name="phosphor-house" class="w-5 h-5" />
                        Go to Dashboard
                    </a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-primary">
                        <x-icon name="phosphor-sign-in" class="w-5 h-5" />
                        Sign In
                    </a>
                @endauth
            </div>
        </div>

        {{-- Visit us (public site details) --}}
        @if($eventConfig && $eventConfig->is_open_to_public && ($eventConfig->public_directions || $eventConfig->public_visiting_hours || $eventConfig->public_contact_email || $eventConfig->public_contact_phone || $eventConfig->public_website_url || $eventConfig->public_description || $eventConfig->talk_in_frequency))
            <div class="border-t border-base-300 pt-8 mb-10" data-testid="public-visit-details">
                <h2 class="text-2xl font-bold text-center mb-6">Visit Us</h2>
                <div class="max-w-2xl mx-auto space-y-4 text-base-content/80">
                    <p class="text-center font-medium">
                        Open to the public
                        @if($eventConfig->club_name)
                            &mdash; hosted by {{ $eventConfig->club_name }}
                        @endif
                    </p>
                    @if($eventConfig->public_description)
                        <p class="leading-relaxed whitespace-pre-line">{{ $eventConfig->public_description }}</p>
                    @endif
                    @if($eventConfig->public_directions)
                        <div>
                            <h3 class="font-semibold">Finding us</h3>
                            <p class="whitespace-pre-line">{{ $eventConfig->public_directions }}</p>
                        </div>
                    @endif
                    @if($eventConfig->public_visiting_hours)
                        <div>
                            <h3 class="font-semibold">Best times to visit</h3>
                            <p class="whitespace-pre-line">{{ $eventConfig->public_visiting_hours }}</p>
                        </div>
                    @endif
                    @if($eventConfig->talk_in_frequency)
                        <div>
                            <h3 class="font-semibold">Talk-in frequency</h3>
                            <p>{{ $eventConfig->talk_in_frequency }}</p>
                        </div>
                    @endif
                    @if($eventConfig->public_contact_email || $eventConfig->public_contact_phone || $eventConfig->public_website_url)
                        <div>
                            <h3 class="font-semibold">Contact</h3>
                            <ul class="break-words">
                                @if($eventConfig->public_contact_email)
                                    <li>Email: <a class="link" href="mailto:{{ $eventConfig->public_contact_email }}">{{ $eventConfig->public_contact_email }}</a></li>
                                @endif
                                @if($eventConfig->public_contact_phone)
                                    <li>Phone: <a class="link" href="tel:{{ preg_replace('/(?!^\+)[^0-9]/', '', trim($eventConfig->public_contact_phone)) }}">{{ $eventConfig->public_contact_phone }}</a></li>
                                @endif
                                @if($eventConfig->public_website_url)
                                    <li>Website: <a class="link" href="{{ $eventConfig->public_website_url }}" target="_blank" rel="noopener nofollow">{{ $eventConfig->public_website_url }}</a></li>
                                @endif
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Live Stats Section (contest events only: unscored events don't keep a contact log) --}}
        @if($activeEvent && $eventConfig && $activeEvent->isScored())
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
        @elseif(! $activeEvent || ! $eventConfig)
            <div class="border-t border-base-300 pt-8">
                <div class="text-center py-12">
                    <x-icon name="phosphor-calendar" class="w-16 h-16 text-base-content/30 mx-auto mb-4" />
                    <p class="text-lg text-base-content/60">No active event at this time. Check back soon!</p>
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
