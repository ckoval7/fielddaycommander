<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\View\Components\Table;
use Illuminate\Support\Facades\Blade;
use Mary\View\Components\Table as MaryTable;

it('still finds the checkbox handler it patches in Mary\'s template', function () {
    $template = (new MaryTable(headers: [], rows: []))->render();

    foreach (array_keys(Table::LEAN_SELECTION) as $original) {
        expect($template)->toContain($original);
    }
});

it('replaces Mary\'s <x-table>', function () {
    expect(Blade::getClassComponentAliases()[config('mary.prefix').'table'])->toBe(Table::class);
});

it('passes only the row key from a selectable checkbox', function () {
    $livewire = new class
    {
        public function getId(): string
        {
            return 'test-component';
        }
    };

    view()->share('__livewire', $livewire);

    $html = Blade::render('<x-table :headers="$headers" :rows="$rows" selectable wire:model="selected" />', [
        'headers' => [['key' => 'call', 'label' => 'Call']],
        'rows' => collect([['id' => 42, 'call' => 'W1AW', 'email' => 'private@example.com']]),
    ]);

    expect($html)
        ->toContain('toggleCheck($el.checked, 42)')
        ->not->toContain('private@example.com');
});
