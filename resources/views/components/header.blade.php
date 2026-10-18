{{-- Desktop header - hidden on mobile --}}
<header class="hidden lg:flex items-center justify-between px-6 py-4 bg-base-100 border-b border-base-300">
    {{-- Left: App Brand --}}
    <div class="flex items-center gap-4">
        <x-app-brand />
    </div>

    {{-- Right: Theme toggle and User menu --}}
    <div class="flex items-center gap-3">
        <x-theme-switch compact />
        <x-user-menu />
    </div>
</header>
