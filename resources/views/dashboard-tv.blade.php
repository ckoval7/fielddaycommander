<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>TV Dashboard - {{ config('app.name') }}</title>
    <x-silence-livewire-rejections />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    @include('livewire.dashboard.layouts.tv', ['event' => $event])
    @livewireScripts
</body>
</html>
