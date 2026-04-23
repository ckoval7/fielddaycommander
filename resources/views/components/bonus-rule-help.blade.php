@props(['rule' => null])

@if ($rule)
    <div x-data="{ expanded: false }" class="contents">
        <button
            type="button"
            x-on:click.stop="expanded = !expanded"
            class="btn btn-ghost btn-xs btn-circle text-info hover:text-info-content hover:bg-info"
            :title="expanded ? 'Hide ARRL rule' : 'Show ARRL rule {{ $rule['section'] }}'"
            :aria-expanded="expanded"
            aria-label="Show ARRL rule {{ $rule['section'] }}"
        >
            <x-icon name="phosphor-question" class="w-5 h-5" />
        </button>

        <div
            x-show="expanded"
            x-collapse
            x-cloak
            class="basis-full mt-2 text-xs text-base-content/70 bg-info/5 border border-info/20 rounded-md px-3 py-2 leading-relaxed"
        >
            <x-icon name="phosphor-lightbulb" class="w-3.5 h-3.5 inline text-info mr-1 -mt-0.5" />
            <span class="font-semibold">ARRL Rule {{ $rule['section'] }}:</span>
            {{ $rule['text'] }}
        </div>
    </div>
@endif
