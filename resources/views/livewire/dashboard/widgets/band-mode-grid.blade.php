{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div wire:poll.visible.30s>
    <x-mary-card title="Band/Mode Activity Grid" shadow separator>
        <x-slot:menu>
            <x-mary-icon name="phosphor-chart-bar" class="w-5 h-5 text-info" />
        </x-slot:menu>

        @if (empty($this->gridData))
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <x-mary-icon name="phosphor-chart-bar" class="w-12 h-12 mx-auto mb-2 opacity-50" />
                <p>No contacts logged yet</p>
            </div>
        @else
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Band and mode activity">
                <table class="w-full text-sm">
                    <thead>
                        <tr>
                            <th scope="col" class="sticky left-0 z-10 bg-base-100 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 pb-2 pr-3 border-b border-gray-200 dark:border-gray-700">
                                Mode
                            </th>
                            @foreach ($this->bands as $band)
                                <th scope="col" class="text-center text-xs font-semibold text-gray-600 dark:text-gray-400 pb-2 px-1.5 whitespace-nowrap border-b border-gray-200 dark:border-gray-700">
                                    {{ $band->name }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->gridData as $row)
                            <tr>
                                <th scope="row" class="sticky left-0 z-10 bg-base-100 py-2 pr-3 border-t border-gray-200 dark:border-gray-700 font-medium text-gray-900 dark:text-gray-100">
                                    {{ $row['mode'] }}
                                </th>
                                @foreach ($this->bands as $band)
                                    <td class="py-2 px-1.5 border-t border-gray-200 dark:border-gray-700 text-center tabular-nums">
                                        @if ($row[$band->id] > 0)
                                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-success/10 text-success font-bold">
                                                {{ $row[$band->id] }}
                                            </span>
                                        @else
                                            <span class="text-base-content/70">-</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-mary-card>
</div>
