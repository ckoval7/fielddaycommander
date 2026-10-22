<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\NetLog;

use App\Events\NetLogChanged;
use App\Models\Band;
use App\Models\Event;
use App\Models\Mode;
use App\Models\Net;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Start Net / Edit Net modal on the net log. Saving or deleting a net
 * dispatches "net-changed" so the net log switches to it, and broadcasts so
 * every other net control screen sees the change.
 */
class NetForm extends Component
{
    public Event $event;

    public bool $showNetForm = false;

    /**
     * The net being edited, or null when starting one.
     */
    public ?int $editingNetId = null;

    public string $netName = '';

    public ?string $netFrequency = null;

    public ?int $netBandId = null;

    public ?int $netModeId = null;

    public ?string $netControlStation = null;

    /**
     * Minutes a station can stay quiet before it shows as overdue, or null
     * for no overdue flag.
     */
    public ?int $netOverdueAfterMinutes = null;

    /**
     * @return Collection<int, Band>
     */
    #[Computed]
    public function bands(): Collection
    {
        return Band::ordered()->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Mode>
     */
    #[Computed]
    public function modes(): Collection
    {
        return Mode::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function editingNet(): ?Net
    {
        return $this->editingNetId === null ? null : $this->findEventNet($this->editingNetId);
    }

    /**
     * Open the form to start a net, or to edit one.
     */
    #[On('open-net-form')]
    public function open(?int $netId = null): void
    {
        $this->editingNetId = $netId;
        unset($this->editingNet);
        $this->authorizeForm();

        $net = $this->editingNet;
        $this->resetValidation();
        $this->netName = $net->name ?? '';
        $this->netFrequency = $net?->frequency;
        $this->netBandId = $net?->band_id;
        $this->netModeId = $net?->mode_id;
        $this->netControlStation = $net?->net_control_station;
        $this->netOverdueAfterMinutes = $net?->overdue_after_minutes;
        $this->showNetForm = true;
    }

    public function save(): void
    {
        $this->authorizeForm();

        $validated = $this->validate([
            'netName' => ['required', 'string', 'max:100'],
            'netFrequency' => ['nullable', 'string', 'max:30'],
            'netBandId' => ['nullable', 'integer', 'exists:bands,id'],
            'netModeId' => ['nullable', 'integer', 'exists:modes,id'],
            'netControlStation' => ['nullable', 'string', 'max:50'],
            'netOverdueAfterMinutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ], attributes: [
            'netName' => 'net name',
            'netFrequency' => 'frequency',
            'netBandId' => 'band',
            'netModeId' => 'mode',
            'netControlStation' => 'net control station',
            'netOverdueAfterMinutes' => 'overdue time',
        ]);

        $attributes = [
            'name' => trim($validated['netName']),
            'frequency' => $this->blankToNull($validated['netFrequency']),
            'band_id' => $validated['netBandId'],
            'mode_id' => $validated['netModeId'],
            'net_control_station' => $this->blankToNull($validated['netControlStation']),
            'overdue_after_minutes' => $validated['netOverdueAfterMinutes'],
        ];

        $net = $this->editingNet;

        if ($net === null) {
            $net = Net::create($attributes + [
                'event_configuration_id' => $this->event->eventConfiguration->id,
                'user_id' => auth()->id(),
            ]);
        } else {
            $net->update($attributes);
        }

        $this->showNetForm = false;
        $this->dispatch('net-changed', netId: $net->id);
        NetLogChanged::dispatch($this->event->id);

        $this->dispatch('toast', title: $this->editingNetId === null ? 'Net started' : 'Net updated', type: 'success');
    }

    /**
     * Delete the net being edited if nothing is logged on it, such as one
     * planned but never run.
     */
    public function delete(): void
    {
        $net = $this->editingNet;

        if ($net === null || ! auth()->user()?->can('delete', $net)) {
            $this->dispatch('toast', title: 'Only an empty net you started can be deleted', type: 'error');

            return;
        }

        $net->delete();
        $this->showNetForm = false;
        $this->dispatch('net-changed', netId: null);
        NetLogChanged::dispatch($this->event->id);
    }

    protected function authorizeForm(): void
    {
        $net = $this->editingNet;

        abort_unless($net === null ? auth()->user()?->can('create', Net::class) : auth()->user()?->can('update', $net), 403);
    }

    /**
     * Find a net that belongs to this form's event, or 404.
     */
    protected function findEventNet(int $netId): Net
    {
        return Net::forEvent($this->event->eventConfiguration->id)->findOrFail($netId);
    }

    protected function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function render(): View
    {
        return view('livewire.net-log.net-form');
    }
}
