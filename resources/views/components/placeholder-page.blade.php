@props(['title', 'icon' => 'phosphor-wrench'])

<div class="flex flex-col items-center justify-center min-h-[60vh] text-center">
    <div class="mb-6">
        <x-icon :name="$icon" class="w-24 h-24 text-base-content/20" />
    </div>

    <h1 class="text-4xl font-bold mb-4">{{ $title }}</h1>

    <p class="text-lg opacity-60 mb-8 max-w-md">
        This page is under construction and will be available soon.
    </p>

    <x-button label="Back to Dashboard" icon="phosphor-house" link="/" class="btn-primary" />
</div>
