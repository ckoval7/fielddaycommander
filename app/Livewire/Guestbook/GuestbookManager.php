<?php

namespace App\Livewire\Guestbook;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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

    private const string BULK_ACTION_LIMIT_MESSAGE = 'Bulk actions limited to 100 entries';

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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterPresence(): void
    {
        $this->resetPage();
    }

    public function updatedFilterCategory(): void
    {
        $this->resetPage();
    }

    public function updatedFilterVerified(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        if (! $this->eventConfig) {
            return GuestbookEntry::query()->whereRaw('1 = 0')->paginate(25);
        }

        return GuestbookEntry::query()
            ->with(['verifiedBy', 'user'])
            ->where('event_configuration_id', $this->eventConfig->id)
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
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate(25);
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

        $baseQuery = GuestbookEntry::where('event_configuration_id', $this->eventConfig->id);

        return [
            'total' => (clone $baseQuery)->count(),
            'verified' => (clone $baseQuery)->where('is_verified', true)->count(),
            'unverified' => (clone $baseQuery)->where('is_verified', false)->count(),
            'in_person' => (clone $baseQuery)->where('presence_type', GuestbookEntry::PRESENCE_TYPE_IN_PERSON)->count(),
            'online' => (clone $baseQuery)->where('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)->count(),
            'bonus_eligible' => (clone $baseQuery)->where('is_verified', true)->bonusEligible()->count(),
        ];
    }

    #[Computed]
    public function editingEntry(): ?GuestbookEntry
    {
        if (! $this->editingEntryId) {
            return null;
        }

        return GuestbookEntry::with(['verifiedBy', 'user'])->find($this->editingEntryId);
    }

    #[Computed]
    public function presenceOptions(): array
    {
        return [
            ['value' => '', 'label' => 'All Presence Types'],
            ['value' => GuestbookEntry::PRESENCE_TYPE_IN_PERSON, 'label' => 'In Person'],
            ['value' => GuestbookEntry::PRESENCE_TYPE_ONLINE, 'label' => 'Online'],
        ];
    }

    #[Computed]
    public function categoryOptions(): array
    {
        return [
            ['value' => '', 'label' => 'All Categories'],
            ['value' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL, 'label' => 'Elected Official'],
            ['value' => GuestbookEntry::VISITOR_CATEGORY_ARRL_OFFICIAL, 'label' => 'ARRL Official'],
            ['value' => GuestbookEntry::VISITOR_CATEGORY_AGENCY, 'label' => 'Agency (FEMA, etc.)'],
            ['value' => GuestbookEntry::VISITOR_CATEGORY_MEDIA, 'label' => 'Media'],
            ['value' => GuestbookEntry::VISITOR_CATEGORY_ARES_RACES, 'label' => 'ARES/RACES'],
            ['value' => GuestbookEntry::VISITOR_CATEGORY_HAM_CLUB, 'label' => 'Ham Club Member'],
            ['value' => GuestbookEntry::VISITOR_CATEGORY_YOUTH, 'label' => 'Youth'],
            ['value' => GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC, 'label' => 'General Public'],
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

        $entry = GuestbookEntry::findOrFail($entryId);
        $this->editingEntryId = $entryId;
        $this->editCategory = $entry->visitor_category;
        $this->editVerified = $entry->is_verified;
        $this->showVerifyModal = true;
    }

    public function closeVerifyModal(): void
    {
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

        $entry = GuestbookEntry::findOrFail($this->editingEntryId);

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
        $this->dispatch('toast', title: 'Success', description: 'Entry updated successfully', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    public function openDeleteModal(int $entryId): void
    {
        Gate::authorize('manage-guestbook');

        $this->deletingEntryId = $entryId;
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

        $entry = GuestbookEntry::findOrFail($this->deletingEntryId);

        AuditLog::log(
            action: 'guestbook.entry.deleted',
            auditable: $entry,
            oldValues: [
                'name' => trim("{$entry->first_name} {$entry->last_name}"),
                'callsign' => $entry->callsign,
            ],
        );

        $entry->delete();

        $this->closeDeleteModal();
        $this->selectedIds = array_diff($this->selectedIds, [$this->deletingEntryId]);
        $this->dispatch('toast', title: 'Success', description: 'Entry deleted successfully', icon: 'phosphor-check-circle', css: 'alert-success');
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
        Gate::authorize('manage-guestbook');

        if (empty($this->selectedIds)) {
            return;
        }

        if (count($this->selectedIds) > 100) {
            $this->dispatch('toast', title: 'Error', description: self::BULK_ACTION_LIMIT_MESSAGE, icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        $affected = GuestbookEntry::whereIn('id', $this->selectedIds)
            ->where('is_verified', false)
            ->update([
                'is_verified' => true,
                'verified_by' => auth()->id(),
                'verified_at' => now(),
            ]);

        AuditLog::log(
            action: 'guestbook.entry.bulk_verified',
            newValues: [
                'count' => $affected,
                'entry_ids' => $this->selectedIds,
            ],
        );

        $this->dispatch('bonus-claimed');

        $count = count($this->selectedIds);
        $this->selectedIds = [];
        $this->dispatch('toast', title: 'Success', description: "{$count} entries verified", icon: 'phosphor-check-circle', css: 'alert-success');
    }

    public function bulkUnverify(): void
    {
        Gate::authorize('manage-guestbook');

        if (empty($this->selectedIds)) {
            return;
        }

        if (count($this->selectedIds) > 100) {
            $this->dispatch('toast', title: 'Error', description: self::BULK_ACTION_LIMIT_MESSAGE, icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        $affected = GuestbookEntry::whereIn('id', $this->selectedIds)
            ->where('is_verified', true)
            ->update([
                'is_verified' => false,
                'verified_by' => null,
                'verified_at' => null,
            ]);

        AuditLog::log(
            action: 'guestbook.entry.bulk_unverified',
            newValues: [
                'count' => $affected,
                'entry_ids' => $this->selectedIds,
            ],
        );

        $this->dispatch('bonus-claimed');

        $count = count($this->selectedIds);
        $this->selectedIds = [];
        $this->dispatch('toast', title: 'Success', description: "{$count} entries unverified", icon: 'phosphor-check-circle', css: 'alert-success');
    }

    public function bulkDelete(): void
    {
        Gate::authorize('manage-guestbook');

        if (empty($this->selectedIds)) {
            return;
        }

        if (count($this->selectedIds) > 100) {
            $this->dispatch('toast', title: 'Error', description: self::BULK_ACTION_LIMIT_MESSAGE, icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        AuditLog::log(
            action: 'guestbook.entry.bulk_deleted',
            newValues: [
                'count' => count($this->selectedIds),
                'entry_ids' => $this->selectedIds,
            ],
        );

        GuestbookEntry::whereIn('id', $this->selectedIds)->delete();

        $count = count($this->selectedIds);
        $this->selectedIds = [];
        $this->dispatch('toast', title: 'Success', description: "{$count} entries deleted", icon: 'phosphor-trash', css: 'alert-success');
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filterPresence = null;
        $this->filterCategory = null;
        $this->filterVerified = null;
        $this->resetPage();
    }

    public function getCategoryLabel(string $category): string
    {
        return match ($category) {
            GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL => 'Elected Official',
            GuestbookEntry::VISITOR_CATEGORY_ARRL_OFFICIAL => 'ARRL Official',
            GuestbookEntry::VISITOR_CATEGORY_AGENCY => 'Agency',
            GuestbookEntry::VISITOR_CATEGORY_MEDIA => 'Media',
            GuestbookEntry::VISITOR_CATEGORY_ARES_RACES => 'ARES/RACES',
            GuestbookEntry::VISITOR_CATEGORY_HAM_CLUB => 'Ham Club',
            GuestbookEntry::VISITOR_CATEGORY_YOUTH => 'Youth',
            GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC => 'General Public',
            default => ucfirst(str_replace('_', ' ', $category)),
        };
    }

    public function exportCsv(): StreamedResponse
    {
        Gate::authorize('manage-guestbook');

        if (! $this->eventConfig) {
            abort(404, 'Event configuration not found');
        }

        $entries = $this->getFilteredEntriesForExport();
        $eventName = Str::slug($this->event->name);
        $filename = "guestbook-{$eventName}-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($entries) {
            $handle = fopen('php://output', 'w');

            // Write CSV header
            fputcsv($handle, [
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
                fputcsv($handle, [
                    trim(($entry->first_name ?? '').' '.($entry->last_name ?? '')),
                    $entry->callsign ?? '',
                    $entry->email ?? '',
                    $this->getCategoryLabel($entry->visitor_category ?? ''),
                    $entry->presence_type === GuestbookEntry::PRESENCE_TYPE_IN_PERSON ? 'In Person' : 'Online',
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

    /**
     * Get filtered entries for export (no pagination).
     *
     * @return Collection<int, GuestbookEntry>
     */
    protected function getFilteredEntriesForExport()
    {
        return GuestbookEntry::query()
            ->with(['verifiedBy', 'user'])
            ->where('event_configuration_id', $this->eventConfig->id)
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
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->get();
    }

    public function render()
    {
        return view('livewire.guestbook.guestbook-manager')->layout('layouts.app');
    }
}
