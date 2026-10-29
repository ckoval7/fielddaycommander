<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\View\Components\ChoicesOffline;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * Render a snippet as if inside a Livewire component, which @entangle needs.
 *
 * @param  array<string, mixed>  $data
 */
function renderChoices(string $blade, array $data = []): string
{
    $livewire = new class
    {
        public function getId(): string
        {
            return 'test-component';
        }
    };

    view()->share('__livewire', $livewire);
    view()->share('errors', view()->shared('errors') ?? new ViewErrorBag);

    return Blade::render($blade, $data);
}

it('replaces Mary\'s <x-choices-offline>', function () {
    expect(Blade::getClassComponentAliases()[config('mary.prefix').'choices-offline'])->toBe(ChoicesOffline::class);
});

it('sends only each option\'s value and label', function () {
    $html = renderChoices('<x-choices-offline wire:model="zone" :options="$options" single searchable />', [
        'options' => collect(timezone_identifiers_list())->map(fn (string $tz) => ['id' => $tz, 'name' => $tz, 'secret' => 'leak-me']),
    ]);

    expect($html)
        ->toContain('choicesOffline(')
        ->toContain('"v":"America/New_York","l":"America/New_York"')
        ->not->toContain('leak-me')
        ->not->toContain('search-value=');

    // ~420 zones used to come to ~900 KB of rendered rows.
    expect(strlen($html))->toBeLessThan(40_000);
});

it('reads the label and sub-label keys it is given', function () {
    $options = [['value' => 'login', 'label' => 'Logged in', 'group' => 'Auth']];
    $component = new ChoicesOffline(optionValue: 'value', optionLabel: 'label', optionSubLabel: 'group', options: $options);

    expect($component->leanOptions())->toBe([['v' => 'login', 'l' => 'Logged in', 's' => 'Auth']]);
});

it('compares values the way Mary did', function () {
    $component = new ChoicesOffline(options: [
        ['id' => 7, 'name' => 'int'],
        ['id' => '12', 'name' => 'numeric string'],
        ['id' => '007', 'name' => 'leading zero'],
    ]);

    expect(array_column($component->leanOptions(), 'v'))->toBe([7, 12, '007']);
});

it('keeps option text from breaking out of the attribute', function () {
    $html = renderChoices('<x-choices-offline wire:model="x" :options="$options" />', [
        'options' => [['id' => 1, 'name' => "O'Brien <script>alert(1)</script> & co"]],
    ]);

    expect($html)
        ->not->toContain('<script>alert(1)')
        ->toContain("data-options='")
        ->toContain('O\\u0027Brien \\u003Cscript\\u003E');
});

it('shows the label, required marker, hint and validation error', function () {
    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag(['zone' => ['Pick a time zone.']]));
    view()->share('errors', $errors);

    $html = renderChoices('<x-choices-offline label="Time Zone" wire:model="zone" :options="[]" hint="Your local zone" required />');

    expect($html)
        ->toContain('Time Zone')
        ->toContain('<span class="text-error">*</span>')
        ->toContain('Your local zone')
        ->toContain('Pick a time zone.')
        ->toContain('!select-error');
});

it('keeps buttons out of the field label so clicks there never remove a chip', function () {
    $html = renderChoices('<x-choices-offline wire:model="selected" :options="$options" searchable />', [
        'options' => [['id' => 1, 'name' => '20m'], ['id' => 2, 'name' => '40m']],
    ]);

    preg_match('~<label\b[^>]*x-ref="container".*?</label>~s', $html, $field);

    expect($field[0])
        ->not->toContain('<button')
        ->toContain('role="button"')
        ->toContain('@click.stop.prevent="remove(option.v)"');
});

it('points the search field at its list', function () {
    $html = renderChoices('<x-choices-offline label="Band" wire:model="selected" :options="[]" />');

    preg_match('~aria-controls="([^"]+)"~', $html, $controls);

    expect($controls)->not->toBeEmpty();
    expect($html)->toMatch('~id="'.preg_quote($controls[1], '~').'"\s+role="listbox"~');
});
