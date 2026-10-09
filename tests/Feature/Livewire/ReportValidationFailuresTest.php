<?php

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
        ->assertSee('aria-label="Dismiss errors"', false);
});
