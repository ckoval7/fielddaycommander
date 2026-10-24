<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Checklists;

use App\Enums\ChecklistScope;
use App\Models\AuditLog;
use App\Models\Checklist;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Services\ChecklistService;
use App\Services\EventContextService;
use App\Support\ChecklistTemplates;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Add, order and remove an event's checklists.
 */
class ManageChecklists extends Component
{
    public ?Event $event = null;

    public ?EventConfiguration $eventConfig = null;

    public bool $showAddModal = false;

    /** One of 'template', 'copy' or 'blank'. */
    public string $addSource = 'template';

    public string $templateKey = '';

    public ?int $copyChecklistId = null;

    public string $blankName = '';

    public string $blankScope = 'event';

    public function mount(): void
    {
        Gate::authorize('manage-shifts');

        $this->event = app(EventContextService::class)->getContextEvent()
            ?? Event::where('is_current', true)->first()
            ?? Event::upcoming()->orderBy('start_time', 'asc')->first();

        $this->eventConfig = $this->event?->eventConfiguration;

        if ($this->eventConfig) {
            ChecklistTemplates::seedDefaults($this->eventConfig);
        }
    }

    /**
     * @return Collection<int, Checklist>
     */
    #[Computed]
    public function checklists(): Collection
    {
        if (! $this->eventConfig) {
            return collect();
        }

        return $this->eventConfig->checklists()->ordered()->withCount(['items', 'runs'])->get();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function templateOptions(): array
    {
        return $this->eventConfig ? ChecklistTemplates::optionsFor($this->eventConfig) : [];
    }

    /**
     * Checklists on other events that can be copied here, newest event first.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    #[Computed]
    public function copyOptions(): Collection
    {
        if (! $this->eventConfig) {
            return collect();
        }

        return Checklist::query()
            ->where('event_configuration_id', '!=', $this->eventConfig->id)
            ->whereHas('eventConfiguration.event')
            ->with('eventConfiguration.event:id,name,start_time')
            ->get()
            ->sortByDesc(fn (Checklist $checklist) => $checklist->eventConfiguration->event->start_time)
            ->map(fn (Checklist $checklist) => [
                'id' => $checklist->id,
                'name' => $checklist->eventConfiguration->event->name.': '.$checklist->name,
            ])
            ->values();
    }

    public function openAddModal(): void
    {
        $this->resetValidation();
        $this->reset('addSource', 'templateKey', 'copyChecklistId', 'blankName', 'blankScope');
        $this->showAddModal = true;
    }

    public function addChecklist(): void
    {
        Gate::authorize('manage-shifts');

        $this->validate([
            'addSource' => ['required', Rule::in(['template', 'copy', 'blank'])],
            'templateKey' => [Rule::requiredIf($this->addSource === 'template'), 'nullable', Rule::in(array_keys($this->templateOptions))],
            'copyChecklistId' => [Rule::requiredIf($this->addSource === 'copy'), 'nullable', Rule::in($this->copyOptions->pluck('id')->all())],
            'blankName' => [Rule::requiredIf($this->addSource === 'blank'), 'nullable', 'string', 'max:255'],
            'blankScope' => ['required', Rule::enum(ChecklistScope::class)],
        ]);

        $checklist = match ($this->addSource) {
            'template' => ChecklistTemplates::create($this->eventConfig, $this->templateKey),
            'copy' => app(ChecklistService::class)->copyTo(Checklist::findOrFail($this->copyChecklistId), $this->eventConfig),
            'blank' => $this->eventConfig->checklists()->create([
                'name' => $this->blankName,
                'scope' => ChecklistScope::from($this->blankScope),
                'sort_order' => (int) $this->eventConfig->checklists()->max('sort_order') + 1,
            ]),
        };

        AuditLog::log(action: 'checklist.created', auditable: $checklist, newValues: [
            'name' => $checklist->name,
            'source' => $this->addSource,
        ]);

        $this->showAddModal = false;

        $this->redirectRoute('checklists.edit', $checklist, navigate: true);
    }

    public function deleteChecklist(int $id): void
    {
        Gate::authorize('manage-shifts');

        $checklist = $this->ownChecklist($id);

        AuditLog::log(action: 'checklist.deleted', auditable: $checklist, oldValues: ['name' => $checklist->name]);

        $checklist->delete();

        // A deleted checklist can no longer back its bonus.
        if ($checklist->earnsBonus()) {
            app(ChecklistService::class)->revokeBonus($checklist);
        }

        unset($this->checklists);

        $this->dispatch('toast', title: 'Checklist deleted', description: "{$checklist->name} was deleted.", icon: 'phosphor-check-circle', css: 'alert-success');
    }

    public function moveUp(int $id): void
    {
        $this->swap($id, '<', 'desc');
    }

    public function moveDown(int $id): void
    {
        $this->swap($id, '>', 'asc');
    }

    public function render(): View
    {
        return view('livewire.checklists.manage-checklists')
            ->layout('layouts.app');
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'templateKey' => 'template',
            'copyChecklistId' => 'checklist to copy',
            'blankName' => 'name',
            'blankScope' => 'checklist scope',
        ];
    }

    private function ownChecklist(int $id): Checklist
    {
        return $this->eventConfig->checklists()->findOrFail($id);
    }

    private function swap(int $id, string $operator, string $direction): void
    {
        Gate::authorize('manage-shifts');

        $checklists = $this->eventConfig->checklists()->ordered()->get();

        // Renumber first so checklists that share a sort order still move.
        $checklists->values()->each(fn (Checklist $checklist, int $index) => $checklist->sort_order === $index ?: $checklist->update(['sort_order' => $index]));

        $current = $checklists->firstWhere('id', $id);
        $neighbor = $checklists
            ->filter(fn (Checklist $checklist) => $operator === '<' ? $checklist->sort_order < $current->sort_order : $checklist->sort_order > $current->sort_order)
            ->sortBy('sort_order', descending: $direction === 'desc')
            ->first();

        if ($current && $neighbor) {
            $original = $current->sort_order;
            $current->update(['sort_order' => $neighbor->sort_order]);
            $neighbor->update(['sort_order' => $original]);
        }

        unset($this->checklists);
    }
}
