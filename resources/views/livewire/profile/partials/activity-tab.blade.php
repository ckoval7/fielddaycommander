<div class="space-y-6">
    <div class="card bg-base-100 shadow">
        <div class="card-body">
            <h3 class="card-title">Your Recent Activity</h3>

            @if($activityLog->isEmpty())
                <x-alert icon="o-information-circle">
                    No recent activity to display.
                </x-alert>
            @else
                {{-- Activity Timeline --}}
                <div class="space-y-4 mt-4">
                    @foreach($activityLog as $activity)
                        <div class="flex items-start gap-4">
                            {{-- Icon based on activity type --}}
                            <div class="mt-1">
                                @switch($activity->action)
                                    @case('user.login.success')
                                        <x-icon name="o-arrow-right-on-rectangle" class="w-5 h-5 text-success" />
                                        @break
                                    @case('user.logout')
                                        <x-icon name="o-arrow-left-on-rectangle" class="w-5 h-5 text-gray-500" />
                                        @break
                                    @case('user.password.changed')
                                        <x-icon name="o-lock-closed" class="w-5 h-5 text-warning" />
                                        @break
                                    @case('user.profile.updated')
                                        <x-icon name="o-pencil" class="w-5 h-5 text-info" />
                                        @break
                                    @default
                                        <x-icon name="o-information-circle" class="w-5 h-5 text-gray-400" />
                                @endswitch
                            </div>

                            {{-- Activity Details --}}
                            <div class="flex-1">
                                <p class="text-sm">
                                    <span class="font-semibold">{{ $activity->action_label }}</span>
                                </p>
                                <p class="text-xs text-gray-500">
                                    {{ $activity->created_at->format('F j, Y g:i A') }}
                                    @if($activity->ip_address)
                                        · {{ $activity->ip_address }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Load More Button --}}
                <div class="card-actions justify-center mt-6">
                    <x-button class="btn-outline" wire:click="loadMoreActivity">
                        Load More
                    </x-button>
                </div>
            @endif
        </div>
    </div>
</div>
