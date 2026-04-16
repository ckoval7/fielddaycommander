@props(['message' => 'No equipment found', 'actionLabel' => null, 'actionRoute' => null, 'colspan' => null])

@if($colspan)
    <tr>
        <td colspan="{{ $colspan }}" class="text-center py-8 text-base-content/60">
@else
    <div class="text-center py-8 text-base-content/60">
@endif
            <x-icon name="phosphor-wrench" class="w-12 h-12 mx-auto mb-2 opacity-50" />
            <p>{{ $message }}</p>
            @if($actionLabel && $actionRoute)
                <x-button :label="$actionLabel" icon="phosphor-plus" class="btn-primary btn-sm mt-2" :link="$actionRoute" wire:navigate />
            @endif
@if($colspan)
        </td>
    </tr>
@else
    </div>
@endif
