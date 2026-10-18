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
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="text-left text-xs font-semibold text-gray-600 dark:text-gray-400 pb-2">
                                Mode
                            </th>
                            @foreach ($this->bands as $band)
                                <th class="text-center text-xs font-semibold text-gray-600 dark:text-gray-400 pb-2">
                                    {{ $band->name }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($this->gridData as $row)
                            <tr>
                                <td class="py-2 font-medium text-gray-900 dark:text-gray-100">
                                    {{ $row['mode'] }}
                                </td>
                                @foreach ($this->bands as $band)
                                    <td class="py-2 text-center tabular-nums">
                                        @if ($row[$band->id] > 0)
                                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-success/10 text-success font-bold">
                                                {{ $row[$band->id] }}
                                            </span>
                                        @else
                                            <span class="text-gray-400 dark:text-gray-600">-</span>
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
