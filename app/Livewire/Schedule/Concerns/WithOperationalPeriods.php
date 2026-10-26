<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Schedule\Concerns;

use App\Models\AuditLog;
use App\Models\OperationalPeriod;
use App\Services\OperationalPeriodService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * Adding, editing and deleting an event's operational periods on the
 * Manage Schedule page. Periods are optional and may not overlap.
 */
trait WithOperationalPeriods
{
    public bool $showPeriodModal = false;

    public ?int $editingPeriodId = null;

    public string $periodName = '';

    public string $periodStartTime = '';

    public string $periodEndTime = '';

    /**
     * @return Collection<int, OperationalPeriod>
     */
    #[Computed]
    public function operationalPeriods(): Collection
    {
        if (! $this->eventConfig) {
            return collect();
        }

        return app(OperationalPeriodService::class)->periods($this->eventConfig);
    }

    public function openPeriodModal(?int $periodId = null): void
    {
        $this->resetValidation();
        $this->reset(['editingPeriodId', 'periodName', 'periodStartTime', 'periodEndTime']);

        if ($periodId) {
            $period = $this->findPeriod($periodId);
            $this->editingPeriodId = $period->id;
            $this->periodName = $period->name;
            $this->periodStartTime = toLocalTime($period->start_time)->format(self::DATETIME_LOCAL_FORMAT);
            $this->periodEndTime = toLocalTime($period->end_time)->format(self::DATETIME_LOCAL_FORMAT);
        } elseif ($last = $this->operationalPeriods->last()) {
            // Start where the last period ended, for the same length.
            $this->periodStartTime = toLocalTime($last->end_time)->format(self::DATETIME_LOCAL_FORMAT);
            $this->periodEndTime = toLocalTime($last->end_time->copy()->add($last->start_time->diff($last->end_time)))->format(self::DATETIME_LOCAL_FORMAT);
        } elseif ($this->event?->start_time) {
            $this->periodStartTime = toLocalTime($this->event->start_time)->format(self::DATETIME_LOCAL_FORMAT);
            $this->periodEndTime = toLocalTime($this->event->end_time ?? $this->event->start_time)->format(self::DATETIME_LOCAL_FORMAT);
        }

        $this->showPeriodModal = true;
    }

    public function savePeriod(): void
    {
        Gate::authorize('manage-shifts');

        $this->validate([
            'periodName' => ['required', 'string', 'max:100'],
            'periodStartTime' => ['required', 'date'],
            'periodEndTime' => ['required', 'date', 'after:periodStartTime'],
        ], [
            'periodEndTime.after' => 'End time must be after start time.',
        ], [
            'periodName' => 'name',
            'periodStartTime' => 'start time',
            'periodEndTime' => 'end time',
        ]);

        $tz = localTimezone();
        $start = Carbon::parse($this->periodStartTime, $tz)->utc();
        $end = Carbon::parse($this->periodEndTime, $tz)->utc();

        $overlapping = $this->eventConfig->operationalPeriods()
            ->when($this->editingPeriodId, fn ($query) => $query->whereKeyNot($this->editingPeriodId))
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->first();

        if ($overlapping) {
            $this->addError('periodStartTime', "This overlaps {$overlapping->name} ({$overlapping->timeRange()}).");

            return;
        }

        $data = [
            'name' => trim($this->periodName),
            'start_time' => $start,
            'end_time' => $end,
        ];

        if ($this->editingPeriodId) {
            $period = $this->findPeriod($this->editingPeriodId);
            $old = $this->periodAuditValues($period);
            $period->update($data);

            AuditLog::log(action: 'operational_period.updated', auditable: $period, oldValues: $old, newValues: $this->periodAuditValues($period));
            $message = 'Operational period updated';
        } else {
            $period = $this->eventConfig->operationalPeriods()->create($data);

            AuditLog::log(action: 'operational_period.created', auditable: $period, newValues: $this->periodAuditValues($period));
            $message = 'Operational period added';
        }

        $this->showPeriodModal = false;
        unset($this->operationalPeriods);

        $this->dispatch('toast', title: 'Success', description: $message, icon: 'phosphor-check-circle', css: 'alert-success');
    }

    public function deletePeriod(int $periodId): void
    {
        Gate::authorize('manage-shifts');

        $period = $this->findPeriod($periodId);

        AuditLog::log(action: 'operational_period.deleted', auditable: $period, oldValues: $this->periodAuditValues($period));

        $period->delete();
        unset($this->operationalPeriods);

        $this->dispatch('toast', title: 'Success', description: 'Operational period deleted', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    private function findPeriod(int $periodId): OperationalPeriod
    {
        return $this->eventConfig->operationalPeriods()->findOrFail($periodId);
    }

    /**
     * @return array{name: string, start_time: string, end_time: string}
     */
    private function periodAuditValues(OperationalPeriod $period): array
    {
        return [
            'name' => $period->name,
            'start_time' => $period->start_time->toIso8601String(),
            'end_time' => $period->end_time->toIso8601String(),
        ];
    }
}
