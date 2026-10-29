<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Hooks\ReportValidationFailures;
use Livewire\Component;
use Livewire\Livewire;

function validationFailureProbe(): Component
{
    return new class extends Component
    {
        public string $name = '';

        public string $callsign = '';

        public function save(): void
        {
            $this->validate(['name' => 'required']);
        }

        public function saveWithManualError(): void
        {
            $this->addError('callsign', 'Callsign is taken.');
        }

        public function toggleSomething(): void {}

        public function updatedName(): void
        {
            $this->validateOnly('name', ['name' => 'min:3']);
        }

        public function render(): string
        {
            return '<div><input wire:model="name"><input wire:model="callsign"></div>';
        }
    };
}

test('flags an action that throws a validation exception', function () {
    $component = Livewire::test(validationFailureProbe())->call('save');

    expect($component->effects[ReportValidationFailures::EFFECT] ?? null)
        ->toHaveKey('name');
});

test('flags an action that adds errors without throwing', function () {
    $component = Livewire::test(validationFailureProbe())->call('saveWithManualError');

    expect($component->effects[ReportValidationFailures::EFFECT] ?? null)
        ->toBe(['callsign' => ['Callsign is taken.']]);
});

test('flags a repeated submit that fails with the same errors', function () {
    $component = Livewire::test(validationFailureProbe())->call('save')->call('save');

    expect($component->effects)->toHaveKey(ReportValidationFailures::EFFECT);
});

test('does not flag an action that passes validation', function () {
    $component = Livewire::test(validationFailureProbe())->set('name', 'Field Day')->call('save');

    expect($component->effects)->not->toHaveKey(ReportValidationFailures::EFFECT);
});

test('does not flag live property validation', function () {
    $component = Livewire::test(validationFailureProbe())->set('name', 'ab');

    $component->assertHasErrors('name');
    expect($component->effects)->not->toHaveKey(ReportValidationFailures::EFFECT);
});

test('does not re-flag stale errors on an unrelated action', function () {
    $component = Livewire::test(validationFailureProbe())->call('save')->call('toggleSomething');

    $component->assertHasErrors('name');
    expect($component->effects)->not->toHaveKey(ReportValidationFailures::EFFECT);
});

test('the floating summary panel renders as a dismissable top-layer popover', function () {
    $this->blade('<x-form-error-summary />')
        ->assertSee('x-data="formErrorSummaryPanel"', false)
        ->assertSee('popover="manual"', false)
        ->assertSee('aria-label="Hide error summary"', false);
});

test('open modals end above the floating summary so it never covers their buttons', function () {
    $css = file_get_contents(resource_path('css/app.css'));
    $script = file_get_contents(resource_path('js/components/form-error-summary.js'));

    // The panel publishes the space it takes at the bottom of the screen while it is open...
    expect($script)
        ->toContain("root.style.setProperty('--form-error-summary-reserve'")
        ->toContain("root.dataset.formErrorSummary = 'open'")
        ->toContain("root.style.removeProperty('--form-error-summary-reserve')");

    // ...and an open modal box shrinks and lifts out of that space.
    expect($css)->toMatch('/:root\[data-form-error-summary="open"\] \.modal-box \{[^}]*max-height: calc\(100dvh - 2rem - var\(--form-error-summary-reserve, 0px\)\);[^}]*margin-bottom: var\(--form-error-summary-reserve, 0px\);/s');
});
