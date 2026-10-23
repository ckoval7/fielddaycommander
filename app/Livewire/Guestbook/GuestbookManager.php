<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Guestbook;

use App\Enums\PresenceType;
use App\Enums\VisitorCategory;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Support\CsvSafe;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuestbookManager extends Component
{
    use WithPagination;

    private const int BULK_ACTION_LIMIT = 100;

    public Event $event;

    public ?EventConfiguration $eventConfig = null;

    // Search and filters
    #[Url]
    public string $search = '';

    #[Url]
    public ?string $filterPresence = null;

    #[Url]
    public ?string $filterCategory = null;

    #[Url]
    public ?string $filterVerified = null;

    /**
     * Columns the entries table may be sorted by.
     */
    protected const SORTABLE_COLUMNS = ['callsign', 'visitor_category', 'presence_type', 'created_at'];

    // Sorting
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Bulk selection
    public array $selectedIds = [];

    // Modal state
    public bool $showVerifyModal = false;

    public bool $showDeleteModal = false;

    public ?int $editingEntryId = null;

    public ?string $editCategory = null;

    public bool $editVerified = false;

    public ?int $deletingEntryId = null;

    public function mount(Event $event): void
    {
        Gate::authorize('manage-guestbook');

        $this->event = $event;
        $this->eventConfig = $event->eventConfiguration;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'filterPresence', 'filterCategory', 'filterVerified'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        if (! $this->eventConfig) {
            return GuestbookEntry::query()->whereRaw('1 = 0')->paginate(25);
        }

        return $this->filteredQuery()->paginate(25);
    }

    /**
     * Entries belonging to this event's configuration.
     *
     * @return Builder<GuestbookEntry>
     */
    protected function eventEntries(): Builder
    {
        return GuestbookEntry::where('event_configuration_id', $this->eventConfig?->id);
    }

    /**
     * Event entries narrowed by the current search, filters and sort.
     *
     * @return Builder<GuestbookEntry>
     */
    protected function filteredQuery(): Builder
    {
        return $this->eventEntries()
            ->with(['verifiedBy', 'user'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('first_name', 'like', "%{$this->search}%")
                        ->orWhere('last_name', 'like', "%{$this->search}%")
                        ->orWhere('callsign', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%");
                });
            })
            ->when($this->filterPresence, function ($query) {
                $query->where('presence_type', $this->filterPresence);
            })
            ->when($this->filterCategory, function ($query) {
                $query->where('visitor_category', $this->filterCategory);
            })
            ->when($this->filterVerified !== null && $this->filterVerified !== '', function ($query) {
                $query->where('is_verified', $this->filterVerified === 'verified');
            })
            ->orderBy(...$this->safeSort());
    }

    /**
     * The requested sort, falling back to newest first for unknown columns or directions.
     *
     * @return array{0: string, 1: string}
     */
    protected function safeSort(): array
    {
        $column = $this->sortBy['column'] ?? null;
        $direction = strtolower((string) ($this->sortBy['direction'] ?? ''));

        return [
            in_array($column, self::SORTABLE_COLUMNS, true) ? $column : 'created_at',
            in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc',
        ];
    }

    /**
     * Visitor categories that earn a bonus under this event's rulebook.
     *
     * @return list<VisitorCategory>
     */
    #[Computed]
    public function bonusCategories(): array
    {
        return $this->event->guestbookBonusCategories();
    }

    public function isBonusCategory(?VisitorCategory $category): bool
    {
        return in_array($category, $this->bonusCategories, true);
    }

    #[Computed]
    public function entryStats(): array
    {
        if (! $this->eventConfig) {
            return [
                'total' => 0,
                'verified' => 0,
                'unverified' => 0,
                'in_person' => 0,
                'online' => 0,
                'bonus_eligible' => 0,
            ];
        }

        // An empty IN () list is invalid SQL, so a rulebook with no guestbook bonuses matches nothing.
        $bonusCategories = array_map(fn (VisitorCategory $category) => $category->value, $this->bonusCategories) ?: [''];
        $bonusPlaceholders = implode(', ', array_fill(0, count($bonusCategories), '?'));

        $row = $this->eventEntries()
            ->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified')
            ->selectRaw('SUM(CASE WHEN is_verified = 0 THEN 1 ELSE 0 END) as unverified')
            ->selectRaw('SUM(CASE WHEN presence_type = ? THEN 1 ELSE 0 END) as in_person', [PresenceType::InPerson->value])
            ->selectRaw('SUM(CASE WHEN presence_type = ? THEN 1 ELSE 0 END) as online', [PresenceType::Online->value])
            ->selectRaw("SUM(CASE WHEN is_verified = 1 AND presence_type <> ? AND visitor_category IN ({$bonusPlaceholders}) THEN 1 ELSE 0 END) as bonus_eligible", [PresenceType::Online->value, ...$bonusCategories])
            ->first();

        return array_map(fn ($count) => (int) $count, (array) $row);
    }

    #[Computed]
    public function editingEntry(): ?GuestbookEntry
    {
        if (! $this->editingEntryId) {
            return null;
        }

        return $this->eventEntries()->with(['verifiedBy', 'user'])->find($this->editingEntryId);
    }

    #[Computed]
    public function presenceOptions(): array
    {
        return [
            ['value' => '', 'label' => 'All Presence Types'],
            ...PresenceType::options(),
        ];
    }

    #[Computed]
    public function categoryOptions(): array
    {
        return [
            ['value' => '', 'label' => 'All Categories'],
            ...VisitorCategory::options(),
        ];
    }

    #[Computed]
    public function verifiedOptions(): array
    {
        return [
            ['value' => '', 'label' => 'All'],
            ['value' => 'verified', 'label' => 'Verified'],
            ['value' => 'unverified', 'label' => 'Unverified'],
        ];
    }

    public function openVerifyModal(int $entryId): void
    {
        Gate::authorize('manage-guestbook');

        $entry = $this->eventEntries()->findOrFail($entryId);
        $this->editingEntryId = $entryId;
        $this->editCategory = $entry->visitor_category->value;
        $this->editVerified = $entry->is_verified;
        $this->showVerifyModal = true;
    }

    public function closeVerifyModal(): void
    {
        $this->resetErrorBag();
        $this->showVerifyModal = false;
        $this->editingEntryId = null;
        $this->editCategory = null;
        $this->editVerified = false;
    }

    public function saveVerification(): void
    {
        Gate::authorize('manage-guestbook');

        if (! $this->editingEntryId) {
            return;
        }

        $entry = $this->eventEntries()->findOrFail($this->editingEntryId);

        if ($this->editVerified && ! GuestbookEntry::canBeVerifiedAs(VisitorCategory::tryFrom((string) $this->editCategory), $entry->presence_type)) {
            $this->addError('editVerified', GuestbookEntry::IN_PERSON_REQUIRED_MESSAGE);

            return;
        }

        $oldValues = [
            'visitor_category' => $entry->visitor_category,
            'is_verified' => $entry->is_verified,
            'verified_by' => $entry->verified_by,
            'verified_at' => $entry->verified_at,
        ];

        $updateData = [
            'visitor_category' => $this->editCategory,
            'is_verified' => $this->editVerified,
        ];

        // Set verification metadata if being verified
        if ($this->editVerified && ! $entry->is_verified) {
            $updateData['verified_by'] = auth()->id();
            $updateData['verified_at'] = now();
        } elseif (! $this->editVerified) {
            $updateData['verified_by'] = null;
            $updateData['verified_at'] = null;
        }

        $entry->update($updateData);

        AuditLog::log(
            action: 'guestbook.entry.updated',
            auditable: $entry,
            oldValues: $oldValues,
            newValues: [
                'visitor_category' => $entry->visitor_category,
                'is_verified' => $entry->is_verified,
                'verified_by' => $entry->verified_by,
                'verified_at' => $entry->verified_at,
            ],
        );

        $this->dispatch('bonus-claimed');

        $this->closeVerifyModal();
        $this->dispatch('toast', title: 'Success', description: 'Entry updated.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    public function openDeleteModal(int $entryId): void
    {
        Gate::authorize('manage-guestbook');

        $this->deletingEntryId = $this->eventEntries()->findOrFail($entryId)->id;
        $this->showDeleteModal = true;
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingEntryId = null;
    }

    public function deleteEntry(): void
    {
        Gate::authorize('manage-guestbook');

        if (! $this->deletingEntryId) {
            return;
        }

        $entry = $this->eventEntries()->findOrFail($this->deletingEntryId);

        AuditLog::log(
            action: 'guestbook.entry.deleted',
            auditable: $entry,
            oldValues: [
                'name' => trim("{$entry->first_name} {$entry->last_name}"),
                'callsign' => $entry->callsign,
            ],
        );

        $entry->delete();

        $this->selectedIds = array_values(array_diff($this->selectedIds, [$entry->id]));
        $this->closeDeleteModal();
        $this->dispatch('toast', title: 'Success', description: 'Entry deleted.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    public function toggleSelectAll(): void
    {
        if (count($this->selectedIds) === $this->entries->count()) {
            $this->selectedIds = [];
        } else {
            $this->selectedIds = $this->entries->pluck('id')->toArray();
        }
    }

    public function bulkVerify(): void
    {
        $this->runBulkAction(
            action: 'bulk_verified',
            verb: 'verified',
            pending: fn (Builder $query) => $query->where('is_verified', false)->whereNot(fn (Builder $q) => $q->online()->whereIn('visitor_category', VisitorCategory::inPersonVisitCategories())),
            skipped: fn (Builder $query) => $query->where('is_verified', false)->online()->whereIn('visitor_category', VisitorCategory::inPersonVisitCategories()),
            apply: fn (GuestbookEntry $entry) => $entry->update([
                'is_verified' => true,
                'verified_by' => auth()->id(),
                'verified_at' => now(),
            ]),
        );
    }

    public function bulkUnverify(): void
    {
        $this->runBulkAction(
            action: 'bulk_unverified',
            verb: 'unverified',
            pending: fn (Builder $query) => $query->where('is_verified', true),
            apply: fn (GuestbookEntry $entry) => $entry->update([
                'is_verified' => false,
                'verified_by' => null,
                'verified_at' => null,
            ]),
        );
    }

    public function bulkDelete(): void
    {
        $this->runBulkAction(
            action: 'bulk_deleted',
            verb: 'deleted',
            pending: fn (Builder $query) => $query,
            apply: fn (GuestbookEntry $entry) => $entry->delete(),
            icon: 'phosphor-trash',
        );
    }

    /**
     * Apply a change to the selected entries in this event, then audit and report it.
     *
     * Entries are changed one model at a time so the observer fires and bonus scoring reconciles.
     *
     * @param  Closure(Builder<GuestbookEntry>): Builder<GuestbookEntry>  $pending  narrows the selection to entries the action would change
     * @param  Closure(GuestbookEntry): mixed  $apply
     * @param  (Closure(Builder<GuestbookEntry>): Builder<GuestbookEntry>)|null  $skipped  selects selected entries the action must leave alone, reported in the toast
     */
    protected function runBulkAction(string $action, string $verb, Closure $pending, Closure $apply, string $icon = 'phosphor-check-circle', ?Closure $skipped = null): void
    {
        Gate::authorize('manage-guestbook');

        if (empty($this->selectedIds)) {
            return;
        }

        if (count($this->selectedIds) > self::BULK_ACTION_LIMIT) {
            $this->dispatch('toast', title: 'Error', description: 'Select no more than '.self::BULK_ACTION_LIMIT.' entries at a time.', icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        $selectedIds = $this->eventEntries()->whereIn('id', $this->selectedIds)->pluck('id')->all();
        $entries = $pending($this->eventEntries()->whereIn('id', $selectedIds))->get();
        $skippedCount = $skipped ? $skipped($this->eventEntries()->whereIn('id', $selectedIds))->count() : 0;

        foreach ($entries as $entry) {
            $apply($entry);
        }

        AuditLog::log(
            action: "guestbook.entry.{$action}",
            newValues: [
                'count' => $entries->count(),
                'entry_ids' => $selectedIds,
            ],
        );

        $this->dispatch('bonus-claimed');

        $this->selectedIds = [];

        if ($skippedCount > 0) {
            $this->dispatch('toast', title: 'Some entries skipped', description: $entries->count().' '.str('entry')->plural($entries->count())." {$verb}. {$skippedCount} online ".str('entry')->plural($skippedCount).' left unverified: agency and elected official visits must be in person to count.', icon: 'phosphor-warning', css: 'alert-warning');

            return;
        }

        $this->dispatch('toast', title: 'Success', description: count($selectedIds).' '.str('entry')->plural(count($selectedIds))." {$verb}.", icon: $icon, css: 'alert-success');
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filterPresence = null;
        $this->filterCategory = null;
        $this->filterVerified = null;
        $this->resetPage();
    }

    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('manage-guestbook');

        if (! $this->eventConfig) {
            abort(404, 'Event configuration not found');
        }

        $entries = $this->filteredQuery()->get();
        $eventName = Str::slug($this->event->name);
        $filename = "guestbook-{$eventName}-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($entries) {
            $handle = fopen('php://output', 'w');

            // Write CSV header
            CsvSafe::put($handle, [
                'Name',
                'Callsign',
                'Email',
                'Category',
                'Presence',
                'Verified',
                'Verified By',
                'Signed At',
            ]);

            // Write data rows
            foreach ($entries as $entry) {
                CsvSafe::put($handle, [
                    trim(($entry->first_name ?? '').' '.($entry->last_name ?? '')),
                    $entry->callsign ?? '',
                    $entry->email ?? '',
                    $entry->visitor_category?->label() ?? '',
                    $entry->presence_type?->label() ?? '',
                    $entry->is_verified ? 'Yes' : 'No',
                    $entry->verifiedBy ? trim(($entry->verifiedBy->first_name ?? '').' '.($entry->verifiedBy->last_name ?? '')) : '',
                    $entry->created_at?->format('Y-m-d H:i:s') ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function render()
    {
        return view('livewire.guestbook.guestbook-manager')->layout('layouts.app');
    }
}
