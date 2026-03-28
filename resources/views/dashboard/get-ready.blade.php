<x-layouts.app>
    <x-slot:title>Get Ready for {{ $event->name }}</x-slot:title>

    <div class="container mx-auto px-4 sm:px-6 py-8">
        <div class="max-w-2xl mx-auto">
            {{-- Event Header --}}
            <div class="text-center mb-8">
                <h1 class="text-3xl font-black text-base-content mb-2">{{ $event->name }}</h1>
                <p class="text-lg text-base-content/70">
                    {{ $event->start_time->format('l, F j, Y') }} at {{ $event->start_time->format('g:i A T') }}
                </p>
                @if($event->eventConfiguration)
                    <div class="flex items-center justify-center gap-4 mt-3 text-sm text-base-content/60">
                        @if($event->eventConfiguration->operatingClass)
                            <span>Class {{ $event->eventConfiguration->operatingClass->name }}</span>
                        @endif
                        @if($event->eventConfiguration->section)
                            <span>Section {{ $event->eventConfiguration->section->name }}</span>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Countdown --}}
            <div class="text-center mb-8"
                 x-data="{
                     endTime: new Date(@js($event->start_time->toIso8601String())),
                     now: new Date(@js(appNow()->toIso8601String())),
                     formattedTime: '--:--:--',
                     intervalId: null,

                     init() {
                         this.updateCountdown();
                         this.intervalId = setInterval(() => {
                             this.now = new Date(this.now.getTime() + 1000);
                             this.updateCountdown();
                         }, 1000);
                     },

                     destroy() {
                         if (this.intervalId) clearInterval(this.intervalId);
                     },

                     updateCountdown() {
                         const diff = this.endTime - this.now;
                         if (diff <= 0) {
                             this.formattedTime = '00:00:00';
                             if (this.intervalId) clearInterval(this.intervalId);
                             return;
                         }
                         const totalSeconds = Math.floor(diff / 1000);
                         const days = Math.floor(totalSeconds / 86400);
                         const hours = Math.floor((totalSeconds % 86400) / 3600);
                         const minutes = Math.floor((totalSeconds % 3600) / 60);
                         const seconds = totalSeconds % 60;
                         const pad = n => String(n).padStart(2, '0');
                         this.formattedTime = days > 0
                             ? `${pad(days)}:${pad(hours)}:${pad(minutes)}:${pad(seconds)}`
                             : `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
                     }
                 }">
                <div class="text-sm text-base-content/60 uppercase tracking-wide mb-1">Starts in</div>
                <div class="text-4xl font-mono font-black text-primary"
                     x-text="formattedTime">
                    --:--:--
                </div>
            </div>

            {{-- Setup Checklist --}}
            <div class="card bg-base-100 shadow-lg">
                <div class="card-body">
                    <h2 class="text-lg font-bold mb-4">Setup Checklist</h2>
                    <div class="space-y-3">
                        @foreach($checklist as $item)
                            <div class="flex items-center gap-3 p-3 rounded-lg {{ $item['done'] ? 'bg-success/5' : 'bg-base-200/50' }}">
                                <div class="flex-shrink-0">
                                    @if($item['done'])
                                        <x-icon name="o-check-circle" class="w-6 h-6 text-success" />
                                    @else
                                        <x-icon name="o-exclamation-circle" class="w-6 h-6 text-base-content/30" />
                                    @endif
                                </div>
                                <div class="flex-1">
                                    <span class="{{ $item['done'] ? 'text-base-content/70' : 'font-medium' }}">
                                        {{ $item['label'] }}
                                    </span>
                                </div>
                                @if($item['route'])
                                    <a href="{{ $item['route'] }}" class="btn btn-ghost btn-sm">
                                        {{ $item['done'] ? 'Review' : 'Set Up' }}
                                    </a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            {{-- Invite Participants CTA --}}
            @can('manage-users')
                <div class="card bg-primary/5 border border-primary/20 mt-6">
                    <div class="card-body text-center py-6">
                        <h3 class="text-lg font-bold mb-1">Invite participants</h3>
                        <p class="text-sm text-base-content/60 mb-4">Get operators signed up so they can claim shifts and log contacts.</p>
                        <a href="{{ route('register') }}" class="btn btn-primary btn-sm">
                            <x-icon name="o-user-plus" class="w-4 h-4" />
                            Share Sign-Up Link
                        </a>
                    </div>
                </div>
            @endcan
        </div>
    </div>
</x-layouts.app>
