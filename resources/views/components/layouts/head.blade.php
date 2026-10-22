{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
The shared head element for the app, guest and guestbook kiosk pages.

Props:
- $title: The full page title
- $viewportFit: Let the page draw under notches (it must pad itself with the safe-area insets)
--}}
@props(['title', 'viewportFit' => false])
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0{{ $viewportFit ? ', viewport-fit=cover' : '' }}">
<meta name="csrf-token" content="{{ csrf_token() }}">
<x-reverb-config-meta />
<title>{{ $title }}</title>
<link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
<x-pwa-meta />

{{-- Set theme before page renders to prevent flash. wire:navigate strips
     unknown attributes from the html element, so re-apply in onSwap (synchronously,
     before paint) to avoid a flash of the prefersdark theme, and again on
     livewire:navigated as a fallback. --}}
<script>
    (function() {
        function applyTheme() {
            let theme = localStorage.getItem('theme');
            if (!theme) {
                theme = 'light';
                localStorage.setItem('theme', theme);
            }
            document.documentElement.setAttribute('data-theme', theme);
            document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'dark' ? '#1d232a' : '#ffffff');
        }
        applyTheme();
        document.addEventListener('livewire:navigating', (event) => event.detail.onSwap(applyTheme));
        document.addEventListener('livewire:navigated', applyTheme);
    })();
</script>

<x-silence-livewire-rejections />

@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
