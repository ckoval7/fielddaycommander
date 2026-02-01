<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans antialiased bg-base-200">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
        {{-- Logo/Brand --}}
        <div class="mb-8">
            <a href="/" class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-lg bg-primary flex items-center justify-center">
                    <x-icon name="o-signal" class="w-8 h-8 text-primary-content" />
                </div>
                <div class="text-left">
                    <div class="font-bold text-2xl">FD Log DB</div>
                    <div class="text-sm opacity-60">Field Day Logging</div>
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
