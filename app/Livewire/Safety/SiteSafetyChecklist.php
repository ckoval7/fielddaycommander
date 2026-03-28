<?php

namespace App\Livewire\Safety;

use App\Enums\ChecklistType;
use App\Models\BonusType;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\SafetyChecklistItem;
use App\Models\ShiftAssignment;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteSafetyChecklist extends Component
{
    public ?EventConfiguration $eventConfig = null;

    public function mount(): void
    {
        $contextService = app(EventContextService::class);
        $event = $contextService->getContextEvent();
        $this->eventConfig = $event?->eventConfiguration;

        // Seed defaults if no items exist
        if ($this->eventConfig && $this->eventConfig->safetyChecklistItems()->count() === 0) {
            SafetyChecklistItem::seedDefaults($this->eventConfig);
        }
    }

    /**
     * Get checklist types applicable to the current operating class.
     *
     * @return list<ChecklistType>
     */
    #[Computed]
    public function checklistTypes(): array
    {
        if (! $this->eventConfig) {
            return [];
        }

        $operatingClass = $this->eventConfig->operatingClass;
        $classLetter = $operatingClass ? preg_replace('/\d+/', '', $operatingClass->code) : null;

        return match ($classLetter) {
            'A' => [ChecklistType::SafetyOfficer],
            'B', 'C', 'D', 'E' => [ChecklistType::SiteResponsibilities],
            'F' => [ChecklistType::SafetyOfficer, ChecklistType::SiteResponsibilities],
            default => [],
        };
    }

    /**
     * Get all checklist items for the current event.
     *
     * @return Collection<int, SafetyChecklistItem>
     */
    #[Computed]
    public function items(): Collection
    {
        if (! $this->eventConfig) {
            return collect();
        }

        return SafetyChecklistItem::forEvent($this->eventConfig->id)
            ->with(['entry', 'entry.completedBy'])
            ->ordered()
            ->get();
    }

    /**
     * Whether the current user can edit checklist items.
     */
    #[Computed]
    public function canEdit(): bool
    {
        if (! $this->eventConfig || ! Auth::check()) {
            return false;
        }

        $safetyRoleNames = ['Safety Officer', 'Site Responsibilities'];

        return ShiftAssignment::query()
            ->where('user_id', Auth::id())
            ->whereIn('status', [
                ShiftAssignment::STATUS_SCHEDULED,
                ShiftAssignment::STATUS_CHECKED_IN,
                ShiftAssignment::STATUS_CHECKED_OUT,
            ])
            ->whereHas('shift', function ($q) use ($safetyRoleNames) {
                $q->where('event_configuration_id', $this->eventConfig->id)
                    ->whereHas('shiftRole', function ($q) use ($safetyRoleNames) {
                        $q->whereIn('name', $safetyRoleNames);
                    });
            })
            ->exists();
    }

    /**
     * Toggle the completion status of a checklist item.
     */
    public function toggleItem(int $itemId): void
    {
        if (! $this->canEdit) {
            return;
        }

        $item = SafetyChecklistItem::findOrFail($itemId);

        if ($item->event_configuration_id !== $this->eventConfig->id) {
            return;
        }

        $entry = $item->entry;
        if (! $entry) {
            return;
        }

        if ($entry->is_completed) {
            $entry->markIncomplete();
            $this->revokeIfGateNotMet($item->checklist_type);
        } else {
            $entry->markComplete(Auth::user());
        }

        unset($this->items);
        $this->dispatch('autosaved');
    }

    /**
     * Update the notes for a checklist item.
     */
    public function updateNotes(int $itemId, ?string $notes): void
    {
        if (! $this->canEdit) {
            return;
        }

        $item = SafetyChecklistItem::findOrFail($itemId);

        if ($item->event_configuration_id !== $this->eventConfig->id) {
            return;
        }

        $entry = $item->entry;
        if (! $entry) {
            return;
        }

        $entry->update(['notes' => $notes]);
        unset($this->items);
        $this->dispatch('autosaved');
    }

    /**
     * Get the completion summary for the checklist.
     *
     * @return array{total: int, completed: int, required_total: int, required_completed: int}
     */
    #[Computed]
    public function completionSummary(): array
    {
        $items = $this->items;
        $total = $items->count();
        $completed = $items->filter(fn ($item) => $item->entry?->is_completed)->count();
        $requiredTotal = $items->filter(fn ($item) => $item->is_required)->count();
        $requiredCompleted = $items->filter(fn ($item) => $item->is_required && $item->entry?->is_completed)->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'required_total' => $requiredTotal,
            'required_completed' => $requiredCompleted,
        ];
    }

    /**
     * Revoke the bonus if the checklist gate is no longer met after unchecking.
     */
    protected function revokeIfGateNotMet(ChecklistType $checklistType): void
    {
        $bonusTypeCode = match ($checklistType) {
            ChecklistType::SafetyOfficer => 'safety_officer',
            ChecklistType::SiteResponsibilities => 'site_responsibilities',
        };

        $bonusType = BonusType::where('code', $bonusTypeCode)->first();
        if (! $bonusType) {
            return;
        }

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->where('bonus_type_id', $bonusType->id)
            ->first();

        if (! $bonus) {
            return;
        }

        // Check if the gate is still met after this uncheck
        if (! $this->checklistGateMet($checklistType)) {
            $bonus->delete();

            $this->dispatch('toast',
                title: 'Bonus Revoked',
                description: 'The '.$checklistType->label().' bonus has been revoked because the checklist is no longer complete.',
                icon: 'o-exclamation-triangle',
                css: 'alert-warning'
            );
        }
    }

    /**
     * Check if the checklist gate is met for a given type.
     */
    protected function checklistGateMet(ChecklistType $checklistType): bool
    {
        $items = SafetyChecklistItem::forEvent($this->eventConfig->id)
            ->byType($checklistType)
            ->with('entry')
            ->get();

        if ($items->isEmpty()) {
            return false;
        }

        $totalCompleted = $items->filter(fn ($item) => $item->entry?->is_completed)->count();
        $requiredCompleted = $items->filter(fn ($item) => $item->is_required && $item->entry?->is_completed)->count();
        $requiredTotal = $items->filter(fn ($item) => $item->is_required)->count();

        if ($checklistType === ChecklistType::SafetyOfficer) {
            return $totalCompleted === $items->count();
        }

        return $requiredCompleted === $requiredTotal && $totalCompleted > ($items->count() / 2);
    }

    public function render(): View
    {
        return view('livewire.safety.site-safety-checklist')
            ->layout('layouts.app');
    }
}
