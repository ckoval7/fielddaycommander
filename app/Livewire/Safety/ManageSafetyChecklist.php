<?php

namespace App\Livewire\Safety;

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\SafetyChecklistEntry;
use App\Models\SafetyChecklistItem;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ManageSafetyChecklist extends Component
{
    public ?Event $event = null;

    public ?EventConfiguration $eventConfig = null;

    // Modal state
    public bool $showItemModal = false;

    public ?int $editingItemId = null;

    public string $itemLabel = '';

    public string $itemHelpText = '';

    public bool $itemIsRequired = false;

    public string $itemChecklistType = '';

    public function mount(): void
    {
        Gate::authorize('manage-shifts');

        $contextService = app(EventContextService::class);
        $this->event = $contextService->getContextEvent();

        if (! $this->event) {
            $this->event = Event::where('is_current', true)->first()
                ?? Event::upcoming()->orderBy('start_time', 'asc')->first();
        }

        $this->eventConfig = $this->event?->eventConfiguration;

        if ($this->eventConfig && $this->eventConfig->safetyChecklistItems()->count() === 0) {
            SafetyChecklistItem::seedDefaults($this->eventConfig);
        }
    }

    /**
     * @return Collection<int, SafetyChecklistItem>
     */
    #[Computed]
    public function items(): Collection
    {
        if (! $this->eventConfig) {
            return collect();
        }

        return SafetyChecklistItem::query()
            ->forEvent($this->eventConfig->id)
            ->ordered()
            ->with('entry')
            ->get();
    }

    public function openItemModal(?int $id = null): void
    {
        $this->resetForm();

        if ($id) {
            $item = SafetyChecklistItem::findOrFail($id);
            $this->editingItemId = $item->id;
            $this->itemLabel = $item->label;
            $this->itemHelpText = $item->help_text ?? '';
            $this->itemIsRequired = $item->is_required;
            $this->itemChecklistType = $item->checklist_type->value;
        }

        $this->showItemModal = true;
    }

    public function saveItem(): void
    {
        Gate::authorize('manage-shifts');

        $this->validate([
            'itemLabel' => ['required', 'string', 'max:500'],
            'itemHelpText' => ['nullable', 'string', 'max:2000'],
            'itemIsRequired' => ['boolean'],
            'itemChecklistType' => $this->editingItemId ? ['nullable'] : ['required', 'string'],
        ]);

        if ($this->editingItemId) {
            $item = SafetyChecklistItem::findOrFail($this->editingItemId);
            $item->update([
                'label' => $this->itemLabel,
                'help_text' => $this->itemHelpText ?: null,
                'is_required' => $this->itemIsRequired,
            ]);
            $message = 'Item updated successfully';
        } else {
            $maxSortOrder = SafetyChecklistItem::query()
                ->forEvent($this->eventConfig->id)
                ->max('sort_order') ?? -1;

            $item = SafetyChecklistItem::create([
                'event_configuration_id' => $this->eventConfig->id,
                'checklist_type' => $this->itemChecklistType,
                'label' => $this->itemLabel,
                'help_text' => $this->itemHelpText ?: null,
                'is_required' => $this->itemIsRequired,
                'is_default' => false,
                'sort_order' => $maxSortOrder + 1,
            ]);

            SafetyChecklistEntry::create([
                'safety_checklist_item_id' => $item->id,
                'is_completed' => false,
            ]);

            $message = 'Item created successfully';
        }

        $this->showItemModal = false;
        $this->resetForm();
        unset($this->items);

        $this->dispatch('toast', title: 'Success', description: $message, icon: 'o-check-circle', css: 'alert-success');
    }

    public function deleteItem(int $id): void
    {
        Gate::authorize('manage-shifts');

        $item = SafetyChecklistItem::findOrFail($id);

        if ($item->is_default) {
            $this->dispatch('toast', title: 'Error', description: 'Cannot delete default ARRL items', icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        $item->delete();

        unset($this->items);

        $this->dispatch('toast', title: 'Success', description: 'Item deleted successfully', icon: 'o-check-circle', css: 'alert-success');
    }

    public function seedDefaults(): void
    {
        Gate::authorize('manage-shifts');

        SafetyChecklistItem::seedDefaults($this->eventConfig);

        unset($this->items);

        $this->dispatch('toast', title: 'Success', description: 'Default items seeded successfully', icon: 'o-check-circle', css: 'alert-success');
    }

    public function moveUp(int $id): void
    {
        Gate::authorize('manage-shifts');

        $item = SafetyChecklistItem::findOrFail($id);

        $previousItem = SafetyChecklistItem::query()
            ->forEvent($this->eventConfig->id)
            ->where('sort_order', '<', $item->sort_order)
            ->orderBy('sort_order', 'desc')
            ->first();

        if ($previousItem) {
            $originalSort = $item->sort_order;
            $item->update(['sort_order' => $previousItem->sort_order]);
            $previousItem->update(['sort_order' => $originalSort]);
        }

        unset($this->items);
    }

    public function moveDown(int $id): void
    {
        Gate::authorize('manage-shifts');

        $item = SafetyChecklistItem::findOrFail($id);

        $nextItem = SafetyChecklistItem::query()
            ->forEvent($this->eventConfig->id)
            ->where('sort_order', '>', $item->sort_order)
            ->orderBy('sort_order', 'asc')
            ->first();

        if ($nextItem) {
            $originalSort = $item->sort_order;
            $item->update(['sort_order' => $nextItem->sort_order]);
            $nextItem->update(['sort_order' => $originalSort]);
        }

        unset($this->items);
    }

    protected function resetForm(): void
    {
        $this->editingItemId = null;
        $this->itemLabel = '';
        $this->itemHelpText = '';
        $this->itemIsRequired = false;
        $this->itemChecklistType = '';
    }

    public function render(): View
    {
        return view('livewire.safety.manage-safety-checklist')
            ->layout('layouts.app');
    }
}
