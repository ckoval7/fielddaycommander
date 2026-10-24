{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
@php
    use App\Enums\ChecklistAnswerType;
    use App\Enums\ChecklistScope;
    $scope = $checklist->scope;
    $subjectName = $this->subjectStation?->name ?? $this->subjectPerson?->call_sign;
@endphp
<div>
    <x-slot:title>{{ $checklist->name }}</x-slot:title>

    <div class="p-6 max-sm:px-0">
        {{-- Header --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between mb-6">
            <div class="min-w-0">
                @unless(\App\Support\StationMode::isActive() || ($scope === ChecklistScope::Person && ! auth()->user()->can('manage-shifts')))
                    <a
                        href="{{ $this->showsRun && $scope !== ChecklistScope::Event ? route('checklists.show', $checklist) : route('checklists.index') }}"
                        wire:navigate
                        class="inline-flex items-center gap-1 text-sm link link-hover pointer-coarse:min-h-11"
                    >
                        <x-icon name="phosphor-arrow-left" class="w-4 h-4" />
                        {{ $this->showsRun && $scope !== ChecklistScope::Event ? $checklist->name : 'Checklists' }}
                    </a>
                @endunless
                <h1 class="text-2xl md:text-3xl font-bold break-words">
                    {{ $checklist->name }}@if($subjectName)<span class="text-base-content/60">: {{ $subjectName }}</span>@endif
                </h1>
                @if($this->subjectStation?->location)
                    <p class="text-base-content/60">{{ $this->subjectStation->location }}</p>
                @elseif($checklist->description && ! $this->showsRun)
                    <p class="text-base-content/60">{{ $checklist->description }}</p>
                @endif
            </div>

            @if($this->showsRun && $this->items->isNotEmpty())
                @php $counts = $this->counts; @endphp
                <div class="flex flex-wrap items-center gap-2">
                    @if($this->run?->completed_at)
                        <x-badge value="Complete" class="badge-success" />
                    @endif
                    <x-badge :value="$counts['answered'] . ' of ' . $counts['total'] . ' answered'" class="badge-info" />
                    @if($counts['required_total'] > 0)
                        <x-badge :value="$counts['required_answered'] . ' of ' . $counts['required_total'] . ' required'" class="badge-warning" />
                    @endif
                    @if($checklist->earnsBonus())
                        <x-badge value="Earns a bonus" class="badge-outline" />
                    @endif
                    {{-- wire:ignore keeps Livewire from morphing the Alpine scope away after each save (#20). --}}
                    <span
                        wire:key="autosave-indicator"
                        wire:ignore
                        x-data="{ show: false }"
                        x-on:autosaved.window="show = true; setTimeout(() => show = false, 1500)"
                        x-show="show"
                        x-transition.opacity
                        class="text-xs text-success font-medium"
                        style="display: none;"
                    >
                        <x-icon name="phosphor-check" class="w-3.5 h-3.5 inline" /> Saved
                    </span>
                </div>
            @endif
        </div>

        @if(! $this->showsRun)
            {{-- Stations or people this checklist covers --}}
            @if($scope === ChecklistScope::Person)
                <div class="mb-4">
                    <x-button label="Open my checklist" icon="phosphor-user" link="{{ route('checklists.show', [$checklist, 'person' => auth()->id()]) }}" class="btn-primary max-sm:w-full" />
                </div>
            @endif

            @if($this->subjects->isEmpty())
                <x-alert icon="phosphor-info" class="alert-info">
                    @if($scope === ChecklistScope::Station)
                        This event has no stations yet.
                    @else
                        Nobody has signed up for a shift at this event yet.
                    @endif
                </x-alert>
            @else
                @php $total = $this->items->count(); @endphp
                <ul class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    @foreach($this->subjects as $subject)
                        <li wire:key="subject-{{ $subject['key'] }}">
                            <a
                                href="{{ route('checklists.show', [$checklist, $scope === ChecklistScope::Station ? 'station' : 'person' => $subject['id']]) }}"
                                wire:navigate
                                class="flex items-center gap-3 p-3 min-h-14 rounded-lg bg-base-100 border border-base-300 hover:border-primary active:bg-base-200"
                            >
                                <x-icon :name="$subject['completed'] ? 'phosphor-check-circle' : ($subject['started'] ? 'phosphor-circle-half' : 'phosphor-circle')" class="w-6 h-6 shrink-0 {{ $subject['completed'] ? 'text-success' : 'text-base-content/50' }}" />
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium truncate">{{ $subject['name'] }}</p>
                                    @if($subject['detail'])
                                        <p class="text-sm text-base-content/60 truncate">{{ $subject['detail'] }}</p>
                                    @endif
                                </div>
                                <span class="text-sm shrink-0 {{ $subject['completed'] ? 'text-success font-medium' : 'text-base-content/70' }}">
                                    @if($subject['completed'])
                                        Done
                                    @elseif($subject['started'])
                                        {{ $subject['answered'] }} of {{ $total }}
                                    @else
                                        Not started
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        @elseif($this->items->isEmpty())
            <x-alert icon="phosphor-info" class="alert-info">
                This checklist has no items yet.
            </x-alert>
        @else
            @php
                $canAnswer = $this->canAnswer;
                $answers = $this->answers;
                $roleNames = $checklist->shiftRoles->pluck('name');
            @endphp

            @unless($canAnswer)
                <x-alert icon="phosphor-lock" class="alert-warning mb-4">
                    @if(\App\Support\StationMode::isActive())
                        Sign in on your own device to work on this checklist.
                    @elseif($roleNames->isNotEmpty())
                        Only volunteers assigned to a <strong>{{ $roleNames->join(' or ') }}</strong> shift for this event can answer items.
                    @else
                        You need a shift at this event or permission to manage the schedule to answer items.
                    @endif
                </x-alert>
            @endunless

            {{-- The item rows are boxed already, so no card around them: on a phone it only adds side padding. --}}
            <div>
                <div class="space-y-3">
                    @foreach($this->items as $item)
                        @php
                            $answer = $answers->get($item->id);
                            $value = $answer?->value;
                            $isAnswered = $value !== null;
                        @endphp
                        <div wire:key="item-{{ $item->id }}" x-data="{ expanded: false }" class="flex flex-col gap-2 p-3 rounded-lg bg-base-200/50 border border-base-300">
                            <div class="flex items-start gap-3">
                                @if($item->answer_type === ChecklistAnswerType::Checkbox)
                                    @if($canAnswer)
                                        <label class="-m-2.5 p-2.5 cursor-pointer shrink-0">
                                            <input
                                                type="checkbox"
                                                class="checkbox checkbox-sm mt-0.5 block"
                                                aria-label="Mark complete: {{ $item->label }}"
                                                @checked($isAnswered)
                                                wire:click="answer({{ $item->id }}, {{ $isAnswered ? 'null' : 'true' }})"
                                            />
                                        </label>
                                    @else
                                        <input type="checkbox" class="checkbox checkbox-sm mt-0.5" aria-label="Mark complete: {{ $item->label }}" @checked($isAnswered) disabled />
                                    @endif
                                @else
                                    <x-icon :name="$isAnswered ? 'phosphor-check-circle' : 'phosphor-circle'" class="w-5 h-5 mt-0.5 shrink-0 {{ $isAnswered ? 'text-success' : 'text-base-content/40' }}" />
                                @endif

                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm {{ $item->answer_type === ChecklistAnswerType::Checkbox && $isAnswered ? 'line-through text-base-content/40' : '' }}">
                                            {{ $item->label }}
                                        </span>
                                        @if($item->is_required)
                                            <x-badge value="Required" class="badge-error badge-xs" />
                                        @endif
                                        @if($item->help_text)
                                            <button
                                                type="button"
                                                x-on:click="expanded = !expanded"
                                                class="btn btn-ghost btn-xs btn-circle text-info hover:text-info-content hover:bg-info"
                                                :aria-label="expanded ? 'Hide details' : 'What does this mean?'"
                                                :aria-expanded="expanded"
                                            >
                                                <x-icon name="phosphor-question" class="w-5 h-5" />
                                            </button>
                                        @endif
                                    </div>

                                    @if($item->help_text)
                                        <div x-show="expanded" x-collapse class="mt-2 text-xs text-base-content/70 bg-info/5 border border-info/20 rounded-md px-3 py-2 leading-relaxed">
                                            <x-icon name="phosphor-lightbulb" class="w-3.5 h-3.5 inline text-info mr-1 -mt-0.5" />
                                            {{ $item->help_text }}
                                        </div>
                                    @endif

                                    @if(str_contains($item->label, 'CPR - AED') && $this->cprAedTrainedUsers->isNotEmpty())
                                        <div class="mt-2 text-xs text-success bg-success/5 border border-success/20 rounded-md px-3 py-2">
                                            <x-icon name="phosphor-heart" class="w-3.5 h-3.5 inline text-success mr-1 -mt-0.5" />
                                            <span class="font-medium">Volunteers marked as CPR/AED trained:</span>
                                            {{ $this->cprAedTrainedUsers->map(fn($u) => $u->call_sign . ' (' . $u->first_name . ')')->join(', ') }}
                                        </div>
                                    @endif

                                    {{-- Answer controls for the other answer types --}}
                                    @if($item->answer_type === ChecklistAnswerType::YesNoNa)
                                        <div class="join mt-2" role="group" aria-label="Answer: {{ $item->label }}">
                                            @foreach(['yes' => 'Yes', 'no' => 'No', 'na' => 'N/A'] as $option => $optionLabel)
                                                <button
                                                    type="button"
                                                    {{-- Livewire marks only the tapped button with data-loading, so a save doesn't grey out every answer on the page. --}}
                                                    class="join-item btn btn-sm min-w-14 touch-manipulation data-loading:pointer-events-none data-loading:opacity-70 {{ $value === $option ? 'btn-primary' : 'btn-outline' }}"
                                                    aria-pressed="{{ $value === $option ? 'true' : 'false' }}"
                                                    @disabled(! $canAnswer)
                                                    wire:click="answer({{ $item->id }}, {{ $value === $option ? 'null' : "'{$option}'" }})"
                                                >{{ $optionLabel }}</button>
                                            @endforeach
                                        </div>
                                    @elseif($item->answer_type === ChecklistAnswerType::Text)
                                        @if($canAnswer)
                                            <input
                                                type="text"
                                                class="input input-sm w-full mt-2"
                                                autocapitalize="sentences"
                                                maxlength="500"
                                                aria-label="Answer: {{ $item->label }}"
                                                value="{{ $value }}"
                                                wire:change="answer({{ $item->id }}, $event.target.value)"
                                            />
                                        @elseif($isAnswered)
                                            <p class="text-sm mt-1 break-words">{{ $value }}</p>
                                        @endif
                                    @elseif($item->answer_type === ChecklistAnswerType::Number)
                                        @if($canAnswer)
                                            <input
                                                type="text"
                                                inputmode="decimal"
                                                class="input input-sm w-36 mt-2"
                                                aria-label="Answer: {{ $item->label }}"
                                                value="{{ $value }}"
                                                wire:change="answer({{ $item->id }}, $event.target.value)"
                                            />
                                        @elseif($isAnswered)
                                            <p class="text-sm mt-1">{{ $value }}</p>
                                        @endif
                                    @endif

                                    @if($isAnswered && $answer->answered_at)
                                        <p class="text-xs text-base-content/50 mt-1">
                                            {{ $item->answer_type === ChecklistAnswerType::Checkbox ? 'Completed' : 'Answered' }} by {{ $answer->answeredBy?->first_name }} {{ $answer->answeredBy?->last_name }}
                                            on {{ $answer->answered_at->format('M j, g:i A') }}
                                        </p>
                                    @endif

                                    @if($canAnswer)
                                        <input
                                            autocapitalize="sentences"
                                            type="text"
                                            class="input input-xs w-full mt-2"
                                            placeholder="Add notes..."
                                            aria-label="Notes: {{ $item->label }}"
                                            value="{{ $answer?->notes }}"
                                            wire:change="saveNotes({{ $item->id }}, $event.target.value)"
                                        />
                                    @elseif($answer?->notes)
                                        <p class="text-xs text-base-content/60 mt-1 italic">{{ $answer->notes }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
