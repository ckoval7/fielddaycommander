@props(['tags' => null, 'size' => 'sm'])

@if($tags && is_array($tags) && count($tags))
    <div class="flex flex-wrap gap-1">
        @foreach($tags as $tag)
            <span class="badge badge-outline badge-{{ $size }}">{{ $tag }}</span>
        @endforeach
    </div>
@else
    <span class="text-xs opacity-50">-</span>
@endif
