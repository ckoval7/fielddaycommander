<?php

namespace App\Livewire\Schedule\Concerns;

use App\Models\ShiftRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

trait WithScheduleFilters
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $role = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $availability = '';

    #[Url]
    public string $timeFilter = '';

    #[Url]
    public string $sortBy = 'time';

    #[Url]
    public string $sortDir = 'asc';

    /**
     * Apply all active filters to a Shift query builder.
     */
    public function applyShiftFilters(Builder $query): Builder
    {
        $query->withCount('assignments');

        if ($this->search !== '') {
            $query->whereHas('assignments.user', function (Builder $q) {
                $q->where(function (Builder $q) {
                    $q->where('first_name', 'like', "%{$this->search}%")
                        ->orWhere('last_name', 'like', "%{$this->search}%")
                        ->orWhere('call_sign', 'like', "%{$this->search}%");
                });
            });
        }

        if ($this->role !== '') {
            $query->where('shift_role_id', (int) $this->role);
        }

        if ($this->status !== '') {
            $query->whereHas('assignments', function (Builder $q) {
                $q->where('status', $this->status);
            });
        }

        if ($this->availability === 'unfilled') {
            $query->havingRaw('assignments_count < shifts.capacity');
        } elseif ($this->availability === 'full') {
            $query->havingRaw('assignments_count >= shifts.capacity');
        }

        if ($this->timeFilter === 'current') {
            $query->where('start_time', '<=', appNow())
                ->where('end_time', '>=', appNow());
        } elseif ($this->timeFilter === 'upcoming') {
            $query->where('start_time', '>', appNow());
        } elseif ($this->timeFilter === 'past') {
            $query->where('end_time', '<', appNow());
        }

        return $query;
    }

    /**
     * Apply all active filters to a ShiftAssignment query builder.
     * Used by MyShifts where queries are on assignments, not shifts.
     */
    public function applyAssignmentFilters(Builder $query): Builder
    {
        if ($this->role !== '') {
            $query->whereHas('shift', function (Builder $q) {
                $q->where('shift_role_id', (int) $this->role);
            });
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return $query;
    }

    /**
     * Apply sorting to a Shift query builder.
     */
    public function applyShiftSorting(Builder $query): Builder
    {
        $dir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        if ($this->sortBy === 'role') {
            $query->join('shift_roles', 'shifts.shift_role_id', '=', 'shift_roles.id')
                ->orderBy('shift_roles.sort_order', $dir)
                ->orderBy('shifts.start_time', 'asc')
                ->select('shifts.*');
        } elseif ($this->sortBy === 'fill') {
            $query->orderByRaw('(assignments_count * 1.0 / shifts.capacity) '.$dir)
                ->orderBy('start_time', 'asc');
        } else {
            $query->orderBy('start_time', $dir);
        }

        return $query;
    }

    /**
     * Get the number of active filters for the badge display.
     */
    #[Computed]
    public function activeFilterCount(): int
    {
        $count = 0;
        if ($this->search !== '') {
            $count++;
        }
        if ($this->role !== '') {
            $count++;
        }
        if ($this->status !== '') {
            $count++;
        }
        if ($this->availability !== '') {
            $count++;
        }
        if ($this->timeFilter !== '') {
            $count++;
        }
        if ($this->sortBy !== 'time' || $this->sortDir !== 'asc') {
            $count++;
        }

        return $count;
    }

    /**
     * Reset all filters to defaults.
     */
    public function resetFilters(): void
    {
        $this->search = '';
        $this->role = '';
        $this->status = '';
        $this->availability = '';
        $this->timeFilter = '';
        $this->sortBy = 'time';
        $this->sortDir = 'asc';
    }

    /**
     * Get available roles for the filter dropdown.
     *
     * @return Collection<int, ShiftRole>
     */
    #[Computed]
    public function filterRoles(): Collection
    {
        if (! $this->eventConfig) {
            return collect();
        }

        return ShiftRole::query()
            ->forEvent($this->eventConfig->id)
            ->ordered()
            ->get();
    }

    /**
     * Get available statuses for the filter dropdown.
     * Override in components for page-specific status lists.
     *
     * @return array<string, string>
     */
    public function getFilterStatuses(): array
    {
        return [
            'scheduled' => 'Scheduled',
            'checked_in' => 'Checked In',
            'checked_out' => 'Checked Out',
            'no_show' => 'No Show',
        ];
    }

    /**
     * Get a human-readable label for an active filter (for pill display).
     *
     * @return array<int, array{key: string, label: string}>
     */
    #[Computed]
    public function activeFilterPills(): array
    {
        $pills = [];

        if ($this->search !== '') {
            $pills[] = ['key' => 'search', 'label' => "Search: {$this->search}"];
        }

        if ($this->role !== '') {
            $roleName = $this->filterRoles->firstWhere('id', (int) $this->role)?->name ?? 'Unknown';
            $pills[] = ['key' => 'role', 'label' => "Role: {$roleName}"];
        }

        if ($this->status !== '') {
            $statusLabel = $this->getFilterStatuses()[$this->status] ?? $this->status;
            $pills[] = ['key' => 'status', 'label' => "Status: {$statusLabel}"];
        }

        if ($this->availability !== '') {
            $pills[] = ['key' => 'availability', 'label' => ucfirst($this->availability)];
        }

        if ($this->timeFilter !== '') {
            $pills[] = ['key' => 'timeFilter', 'label' => ucfirst($this->timeFilter)];
        }

        return $pills;
    }

    /**
     * Remove a single filter by key.
     */
    public function removeFilter(string $key): void
    {
        match ($key) {
            'search' => $this->search = '',
            'role' => $this->role = '',
            'status' => $this->status = '',
            'availability' => $this->availability = '',
            'timeFilter' => $this->timeFilter = '',
            default => null,
        };
    }
}
