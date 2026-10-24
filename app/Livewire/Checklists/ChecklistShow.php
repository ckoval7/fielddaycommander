<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Checklists;

use App\Enums\ChecklistScope;
use App\Models\Checklist;
use App\Models\ChecklistAnswer;
use App\Models\ChecklistItem;
use App\Models\ChecklistRun;
use App\Models\Station;
use App\Models\User;
use App\Services\ChecklistService;
use App\Support\BroadcastChannels;
use App\Support\StationMode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One checklist: the list of stations or people it covers, or a single
 * run to work through.
 */
class ChecklistShow extends Component
{
    public Checklist $checklist;

    #[Url]
    public ?int $station = null;

    #[Url]
    public ?int $person = null;

    public function mount(Checklist $checklist): void
    {
        $this->checklist = $checklist;

        if (StationMode::isActive()) {
            abort_unless($checklist->scope === ChecklistScope::Station, 403);
            $this->station = StationMode::boundStationId();
        }

        // Volunteers only work on their own per-person checklist.
        if ($checklist->scope === ChecklistScope::Person && ! Auth::user()->can('manage-shifts')) {
            $this->person = Auth::id();
        }
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            BroadcastChannels::privateListener(
                BroadcastChannels::event($this->checklist->eventConfiguration->event_id),
                '.'.BroadcastChannels::CHECKLIST_CHANGED,
            ) => 'refreshAnswers',
        ];
    }

    public function refreshAnswers(): void
    {
        unset($this->run, $this->answers, $this->counts, $this->subjects);
    }

    /**
     * Whether this page shows a single run rather than a list of subjects.
     */
    #[Computed]
    public function showsRun(): bool
    {
        return match ($this->checklist->scope) {
            ChecklistScope::Event => true,
            ChecklistScope::Station => $this->subjectStation !== null,
            ChecklistScope::Person => $this->subjectPerson !== null,
        };
    }

    #[Computed]
    public function subjectStation(): ?Station
    {
        if ($this->station === null || $this->checklist->scope !== ChecklistScope::Station) {
            return null;
        }

        return Station::forEvent($this->checklist->event_configuration_id)->find($this->station);
    }

    #[Computed]
    public function subjectPerson(): ?User
    {
        if ($this->person === null || $this->checklist->scope !== ChecklistScope::Person) {
            return null;
        }

        if ($this->person !== Auth::id() && ! Auth::user()->can('manage-shifts')) {
            return null;
        }

        return User::find($this->person);
    }

    /**
     * The run on screen. It isn't saved until someone answers an item, so
     * opening a checklist doesn't count as starting it.
     */
    #[Computed]
    public function run(): ?ChecklistRun
    {
        if (! $this->showsRun) {
            return null;
        }

        $key = match ($this->checklist->scope) {
            ChecklistScope::Event => ChecklistRun::EVENT_SUBJECT,
            ChecklistScope::Station => ChecklistRun::stationKey($this->subjectStation->id),
            ChecklistScope::Person => ChecklistRun::userKey($this->subjectPerson->id),
        };

        return $this->checklist->runs()->where('subject_key', $key)->first()
            ?? new ChecklistRun([
                'checklist_id' => $this->checklist->id,
                'subject_key' => $key,
                'station_id' => $this->subjectStation?->id,
                'user_id' => $this->subjectPerson?->id,
            ]);
    }

    /**
     * @return Collection<int, ChecklistItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return $this->checklist->items()->get();
    }

    /**
     * Answers on the run, keyed by item id.
     *
     * @return Collection<int, ChecklistAnswer>
     */
    #[Computed]
    public function answers(): Collection
    {
        if (! $this->run?->exists) {
            return collect();
        }

        return $this->run->answers()->with('answeredBy:id,call_sign,first_name,last_name')->get()->keyBy('checklist_item_id');
    }

    /**
     * @return array{total: int, answered: int, required_total: int, required_answered: int}
     */
    #[Computed]
    public function counts(): array
    {
        $answered = $this->answers->filter(fn ($answer) => $answer->value !== null);

        return [
            'total' => $this->items->count(),
            'answered' => $this->items->filter(fn ($item) => $answered->has($item->id))->count(),
            'required_total' => $this->items->where('is_required', true)->count(),
            'required_answered' => $this->items->filter(fn ($item) => $item->is_required && $answered->has($item->id))->count(),
        ];
    }

    #[Computed]
    public function canAnswer(): bool
    {
        return $this->run !== null && app(ChecklistService::class)->canAnswer(Auth::user(), $this->run);
    }

    /**
     * Stations or people the checklist covers, with how far each run has got.
     *
     * @return Collection<int, array{id: int, name: string, detail: string|null, answered: int, completed: bool, started: bool}>
     */
    #[Computed]
    public function subjects(): Collection
    {
        $service = app(ChecklistService::class);

        $runs = $this->checklist->runs()
            ->withCount(['answers as answered_count' => fn ($q) => $q->whereNotNull('value')->whereHas('item')])
            ->get()
            ->keyBy('subject_key');

        $subjects = match ($this->checklist->scope) {
            ChecklistScope::Station => $service->stationsFor($this->checklist)->map(fn (Station $station) => [
                'id' => $station->id,
                'key' => ChecklistRun::stationKey($station->id),
                'name' => $station->name,
                'detail' => $station->location,
            ]),
            ChecklistScope::Person => $service->peopleFor($this->checklist)->map(fn (User $user) => [
                'id' => $user->id,
                'key' => ChecklistRun::userKey($user->id),
                'name' => $user->call_sign,
                'detail' => trim("{$user->first_name} {$user->last_name}") ?: null,
            ]),
            ChecklistScope::Event => collect(),
        };

        return $subjects->map(function (array $subject) use ($runs) {
            $run = $runs->get($subject['key']);

            return [
                ...$subject,
                'answered' => $run?->answered_count ?? 0,
                'completed' => $run?->completed_at !== null,
                'started' => $run !== null,
            ];
        })->values();
    }

    /**
     * Users marked CPR/AED trained, shown beside the ARRL CPR item.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function cprAedTrainedUsers(): Collection
    {
        return User::query()
            ->where('is_cpr_aed_trained', true)
            ->excludeSystem()
            ->orderBy('call_sign')
            ->get(['id', 'call_sign', 'first_name']);
    }

    /**
     * Answer an item. Null, an empty string or an unticked box clears it.
     */
    public function answer(int $itemId, mixed $value): void
    {
        $item = $this->editableItem($itemId);

        if (! $item) {
            return;
        }

        $service = app(ChecklistService::class);
        $run = $service->runFor($this->checklist, $this->subjectStation, $this->subjectPerson);

        $this->toastBonusChange($service->answer($run, $item, $value, Auth::user()));

        $this->refreshAnswers();
        $this->dispatch('autosaved');
    }

    public function saveNotes(int $itemId, ?string $notes): void
    {
        $item = $this->editableItem($itemId);

        if (! $item) {
            return;
        }

        $service = app(ChecklistService::class);
        $service->saveNotes($service->runFor($this->checklist, $this->subjectStation, $this->subjectPerson), $item, $notes);

        $this->refreshAnswers();
        $this->dispatch('autosaved');
    }

    public function render(): View
    {
        return view('livewire.checklists.checklist-show')
            ->layout('layouts.app');
    }

    /**
     * The item, if it belongs to this checklist and the user may answer it.
     */
    private function editableItem(int $itemId): ?ChecklistItem
    {
        if (! $this->canAnswer) {
            return null;
        }

        return $this->checklist->items()->whereKey($itemId)->first();
    }

    private function toastBonusChange(?string $change): void
    {
        if ($change === ChecklistService::BONUS_CLAIMED) {
            $this->dispatch('bonus-claimed');
            $this->dispatch('toast',
                title: 'Bonus earned',
                description: "The {$this->checklist->name} bonus is recorded. Include the signed checklist with your ARRL submission.",
                icon: 'phosphor-check-circle',
                css: 'alert-success',
            );
        } elseif ($change === ChecklistService::BONUS_REVOKED) {
            $this->dispatch('toast',
                title: 'Bonus removed',
                description: "The {$this->checklist->name} bonus was removed. The checklist is no longer complete.",
                icon: 'phosphor-warning',
                css: 'alert-warning',
            );
        }
    }
}
