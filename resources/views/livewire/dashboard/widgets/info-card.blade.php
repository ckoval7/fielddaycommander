{{--
InfoCard Widget View

Displays event information in key-value pairs.
Supports normal and TV size variants.

Props from component:
- $data: Array with 'event_name', 'location', 'operating_class', 'call_sign'
- $size: 'normal' or 'tv'
--}}

<div class="h-full">
    @if ($size === 'tv')
        {{-- TV Mode: Large display for kiosk/TV dashboards --}}
        <x-card class="h-full flex flex-col justify-center p-6 sm:p-8" shadow>
            <div class="space-y-4 sm:space-y-6">
                <div>
                    <div class="text-sm sm:text-base text-base-content/60 mb-1">
                        Event
                    </div>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold text-base-content break-words">
                        {{ $data['event_name'] }}
                    </div>
                </div>

                <div>
                    <div class="text-sm sm:text-base text-base-content/60 mb-1">
                        Location
                    </div>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold text-base-content break-words">
                        {{ $data['location'] }}
                    </div>
                </div>

                <div>
                    <div class="text-sm sm:text-base text-base-content/60 mb-1">
                        Class
                    </div>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold text-base-content break-words">
                        {{ $data['operating_class'] }}
                    </div>
                </div>

                <div>
                    <div class="text-sm sm:text-base text-base-content/60 mb-1">
                        Call Sign
                    </div>
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold text-primary break-words">
                        {{ $data['call_sign'] }}
                    </div>
                </div>
            </div>
        </x-card>
    @else
        {{-- Normal Mode: Compact info display --}}
        <x-card class="h-full" shadow>
            <x-slot name="title">Event Info</x-slot>

            <div class="space-y-3">
                <div class="flex justify-between items-start min-w-0">
                    <span class="text-sm text-base-content/60 flex-shrink-0">Event:</span>
                    <span class="text-sm font-medium text-base-content truncate ml-2">
                        {{ $data['event_name'] }}
                    </span>
                </div>

                <div class="divider my-2"></div>

                <div class="flex justify-between items-start min-w-0">
                    <span class="text-sm text-base-content/60 flex-shrink-0">Location:</span>
                    <span class="text-sm font-medium text-base-content truncate ml-2">
                        {{ $data['location'] }}
                    </span>
                </div>

                <div class="divider my-2"></div>

                <div class="flex justify-between items-start min-w-0">
                    <span class="text-sm text-base-content/60 flex-shrink-0">Class:</span>
                    <span class="text-sm font-medium text-base-content truncate ml-2">
                        {{ $data['operating_class'] }}
                    </span>
                </div>

                <div class="divider my-2"></div>

                <div class="flex justify-between items-start min-w-0">
                    <span class="text-sm text-base-content/60 flex-shrink-0">Call Sign:</span>
                    <span class="text-sm font-bold text-primary truncate ml-2">
                        {{ $data['call_sign'] }}
                    </span>
                </div>
            </div>
        </x-card>
    @endif
</div>
