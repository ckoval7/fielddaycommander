<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Checklists;

use App\Enums\ChecklistAnswerType;
use App\Enums\ChecklistCompletionRule;
use App\Enums\ChecklistScope;
use App\Models\AuditLog;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ShiftRole;
use App\Services\ChecklistService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Edit one checklist's settings and items.
 */
class EditChecklist extends Component
{
    public Checklist $checklist;

    public string $name = '';

    public string $description = '';

    public string $scope = 'event';

    public string $completionRule = 'required_items';

    /** @var list<int> */
    public array $shiftRoleIds = [];

    public bool $showItemModal = false;

    public ?int $editingItemId = null;

    public string $itemLabel = '';

    public string $itemHelpText = '';

    public string $itemAnswerType = 'checkbox';

    public bool $itemIsRequired = false;

    public function mount(Checklist $checklist): void
    {
        Gate::authorize('manage-shifts');

        $this->checklist = $checklist;
        $this->name = $checklist->name;
        $this->description = $checklist->description ?? '';
        $this->scope = $checklist->scope->value;
        $this->completionRule = $checklist->completion_rule->value;
        $this->shiftRoleIds = $checklist->shiftRoles()->pluck('shift_roles.id')->all();
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
     * @return Collection<int, ShiftRole>
     */
    #[Computed]
    public function shiftRoleOptions(): Collection
    {
        return ShiftRole::where('event_configuration_id', $this->checklist->event_configuration_id)
            ->orderBy('sort_order')
            ->get(['id', 'name']);
    }

    /**
     * A checklist that has runs keeps its scope, so answers stay with the
     * station or person they were given for.
     */
    #[Computed]
    public function scopeLocked(): bool
    {
        return $this->checklist->runs()->exists();
    }

    public function saveSettings(): void
    {
        Gate::authorize('manage-shifts');

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scope' => ['required', Rule::enum(ChecklistScope::class)],
            'completionRule' => ['required', Rule::enum(ChecklistCompletionRule::class)],
            'shiftRoleIds' => ['array'],
            'shiftRoleIds.*' => ['integer', Rule::in($this->shiftRoleOptions->pluck('id')->all())],
        ]);

        $old = $this->checklist->only(['name', 'description', 'scope', 'completion_rule']);

        $this->checklist->update([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'scope' => $this->scopeLocked ? $this->checklist->scope : ChecklistScope::from($this->scope),
            // The bonus gate follows the ARRL rule, so a bonus checklist keeps it.
            'completion_rule' => $this->checklist->bonus_code ? $this->checklist->completion_rule : ChecklistCompletionRule::from($this->completionRule),
        ]);
        $this->checklist->shiftRoles()->sync($this->shiftRoleIds);

        AuditLog::log(action: 'checklist.updated', auditable: $this->checklist, oldValues: $old, newValues: $this->checklist->only(['name', 'description', 'scope', 'completion_rule']));

        $this->scope = $this->checklist->scope->value;
        $this->completionRule = $this->checklist->completion_rule->value;
        $this->refreshRuns();

        $this->dispatch('toast', title: 'Settings saved', description: 'Checklist settings updated.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    public function openItemModal(?int $id = null): void
    {
        $this->resetValidation();
        $this->reset('editingItemId', 'itemLabel', 'itemHelpText', 'itemAnswerType', 'itemIsRequired');

        if ($id) {
            $item = $this->ownItem($id);
            $this->editingItemId = $item->id;
            $this->itemLabel = $item->label;
            $this->itemHelpText = $item->help_text ?? '';
            $this->itemAnswerType = $item->answer_type->value;
            $this->itemIsRequired = $item->is_required;
        }

        $this->showItemModal = true;
    }

    public function saveItem(): void
    {
        Gate::authorize('manage-shifts');

        $this->validate([
            'itemLabel' => ['required', 'string', 'max:500'],
            'itemHelpText' => ['nullable', 'string', 'max:2000'],
            'itemAnswerType' => ['required', Rule::enum(ChecklistAnswerType::class)],
            'itemIsRequired' => ['boolean'],
        ]);

        $attributes = [
            'label' => $this->itemLabel,
            'help_text' => $this->itemHelpText ?: null,
            'answer_type' => ChecklistAnswerType::from($this->itemAnswerType),
            'is_required' => $this->itemIsRequired,
        ];

        if ($this->editingItemId) {
            $item = $this->ownItem($this->editingItemId);
            $old = $item->only(array_keys($attributes));
            $item->update($attributes);
            AuditLog::log(action: 'checklist.item.updated', auditable: $item, oldValues: $old, newValues: $item->only(array_keys($attributes)));
        } else {
            $item = $this->checklist->items()->create([
                ...$attributes,
                'sort_order' => (int) $this->checklist->items()->max('sort_order') + 1,
            ]);
            AuditLog::log(action: 'checklist.item.created', auditable: $item, newValues: ['checklist' => $this->checklist->name, 'label' => $item->label]);
        }

        $this->showItemModal = false;
        unset($this->items);
        $this->refreshRuns();

        $this->dispatch('toast', title: 'Item saved', description: $this->editingItemId ? 'Checklist item updated.' : 'Checklist item added.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    public function deleteItem(int $id): void
    {
        Gate::authorize('manage-shifts');

        $item = $this->ownItem($id);

        if ($item->is_default) {
            $this->dispatch('toast', title: 'Can\'t delete this item', description: 'Items from the ARRL checklist can\'t be deleted.', icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        AuditLog::log(action: 'checklist.item.deleted', auditable: $item, oldValues: ['checklist' => $this->checklist->name, 'label' => $item->label]);

        $item->delete();
        unset($this->items);
        $this->refreshRuns();
    }

    public function moveUp(int $id): void
    {
        $this->swap($id, -1);
    }

    public function moveDown(int $id): void
    {
        $this->swap($id, 1);
    }

    public function render(): View
    {
        return view('livewire.checklists.edit-checklist')
            ->layout('layouts.app');
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'completionRule' => 'completion rule',
            'shiftRoleIds' => 'shift roles',
            'itemLabel' => 'item text',
            'itemHelpText' => 'help text',
            'itemAnswerType' => 'answer type',
        ];
    }

    private function ownItem(int $id): ChecklistItem
    {
        return $this->checklist->items()->findOrFail($id);
    }

    /**
     * Items and rules changed, so re-check every run (and the bonus).
     */
    private function refreshRuns(): void
    {
        $service = app(ChecklistService::class);

        $this->checklist->refresh();
        $this->checklist->runs()->each(fn ($run) => $service->refreshCompletion($run));
    }

    private function swap(int $id, int $direction): void
    {
        Gate::authorize('manage-shifts');

        $items = $this->checklist->items()->get()->values();
        $index = $items->search(fn (ChecklistItem $item) => $item->id === $id);
        $neighbor = $index === false ? null : $items->get($index + $direction);

        if ($neighbor) {
            $items->each(fn (ChecklistItem $item, int $position) => $item->sort_order === $position ?: $item->update(['sort_order' => $position]));
            $items->get($index)->update(['sort_order' => $index + $direction]);
            $neighbor->update(['sort_order' => $index]);
        }

        unset($this->items);
    }
}
