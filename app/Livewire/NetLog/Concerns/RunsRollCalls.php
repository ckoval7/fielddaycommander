<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\NetLog\Concerns;

use App\Events\NetLogChanged;
use App\Exceptions\RollCallException;
use App\Models\NetLogEntry;
use App\Models\RollCall;
use App\Models\RollCallStation;
use App\Services\RollCallService;
use Livewire\Attributes\Computed;

/**
 * Starting, marking and ending a roll call (PAR) on the net being logged.
 * Anyone who can log net traffic can run one.
 */
trait RunsRollCalls
{
    /**
     * The roll call open on this net, with its stations in name order.
     */
    #[Computed]
    public function activeRollCall(): ?RollCall
    {
        if ($this->currentNet === null) {
            return null;
        }

        $rollCall = $this->currentNet->rollCalls()->active()->with('stations')->first();
        $rollCall?->setRelation('stations', $rollCall->stations->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values());

        return $rollCall;
    }

    public function startRollCall(): void
    {
        $this->authorizeRollCall();

        if ($this->currentNet === null) {
            return;
        }

        try {
            app(RollCallService::class)->start($this->currentNet, auth()->user());
        } catch (RollCallException $exception) {
            $this->dispatch('toast', title: $exception->getMessage(), type: 'error');

            return;
        }

        $this->rollCallChanged();
    }

    public function markAccounted(int $rollCallStationId): void
    {
        $this->authorizeRollCall();
        app(RollCallService::class)->markAccounted($this->findRollCallStation($rollCallStationId), auth()->user());
        $this->rollCallChanged();
    }

    public function unmarkAccounted(int $rollCallStationId): void
    {
        $this->authorizeRollCall();
        app(RollCallService::class)->unmark($this->findRollCallStation($rollCallStationId));
        $this->rollCallChanged();
    }

    public function endRollCall(): void
    {
        $this->authorizeRollCall();

        if ($this->activeRollCall === null) {
            return;
        }

        app(RollCallService::class)->end($this->activeRollCall, auth()->user());
        $this->rollCallChanged();
    }

    /**
     * Account for the station a newly logged or edited entry came from.
     */
    protected function recordRollCallAnswer(NetLogEntry $entry): void
    {
        app(RollCallService::class)->recordEntry($entry);
        unset($this->activeRollCall);
    }

    protected function authorizeRollCall(): void
    {
        abort_unless(auth()->user()?->can('create', NetLogEntry::class), 403);
    }

    /**
     * A station on an open roll call on this net, or 404.
     */
    protected function findRollCallStation(int $rollCallStationId): RollCallStation
    {
        return RollCallStation::query()
            ->whereHas('rollCall', fn ($query) => $query->active()->where('net_id', $this->currentNet?->id))
            ->with('rollCall.net')
            ->findOrFail($rollCallStationId);
    }

    protected function rollCallChanged(): void
    {
        NetLogChanged::dispatch($this->event->id);
        unset($this->activeRollCall, $this->entries, $this->stationStatus);
    }
}
