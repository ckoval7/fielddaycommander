{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    {{-- Start / Edit Net --}}
    <x-modal wire:model="showNetForm" :title="$editingNetId ? 'Edit Net' : 'Start Net'" class="backdrop-blur" separator>
        <form wire:submit="save" class="space-y-4">
            <x-input label="Net name" wire:model="netName" placeholder="Local 2m Net" autocomplete="off" required />

            <x-input
                label="Frequency"
                wire:model="netFrequency"
                placeholder="146.520 MHz"
                autocomplete="off"
            />

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select
                    label="Band"
                    wire:model="netBandId"
                    :options="$this->bands"
                    option-value="id"
                    option-label="name"
                    placeholder="None"
                />
                <x-select
                    label="Mode"
                    wire:model="netModeId"
                    :options="$this->modes"
                    option-value="id"
                    option-label="name"
                    placeholder="None"
                />
            </div>

            <x-input
                label="Net control station"
                wire:model="netControlStation"
                placeholder="NCS"
                hint="Callsign or tactical name. It's recognised when you type it in a line."
                autocomplete="off"
                autocapitalize="characters"
            />

            <div class="flex flex-wrap justify-between gap-2 pt-2">
                <div>
                    @if($this->editingNet)
                        @can('delete', $this->editingNet)
                            <x-button
                                label="Delete net"
                                icon="phosphor-trash"
                                class="btn-ghost text-error min-h-11"
                                wire:click="delete"
                                wire:confirm="Delete this net? It has nothing logged on it."
                                spinner="delete"
                            />
                        @endcan
                    @endif
                </div>
                <div class="flex gap-2">
                    <x-button label="Cancel" class="btn-ghost min-h-11" @click="$wire.showNetForm = false" />
                    <x-button
                        type="submit"
                        :label="$editingNetId ? 'Save' : 'Start net'"
                        icon="phosphor-check"
                        class="btn-primary min-h-11"
                        spinner="save"
                    />
                </div>
            </div>
        </form>
    </x-modal>
</div>
