<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * Renders a Blade string with the given validation errors shared.
 *
 * @param  array<string, string>  $errors
 */
function renderWithErrors(string $blade, array $errors = []): string
{
    view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag($errors)));

    return Blade::render($blade);
}

it('still finds every patched snippet in the Mary templates', function (string $component) {
    // Build Mary's component with its default props, without Blade.
    $reflection = new ReflectionClass(get_parent_class($component));
    $instance = $reflection->newInstanceWithoutConstructor();
    foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
        if ($parameter->isPromoted() && $parameter->isDefaultValueAvailable()) {
            $reflection->getProperty($parameter->getName())->setValue($instance, $parameter->getDefaultValue());
        }
    }
    $template = $instance->render();

    foreach (array_keys($component::templateReplacements()) as $snippet) {
        if (in_array($snippet, ['<div class="{{ $errorClass }}" x-class="text-error">', '<div class="{{ $errorClass }}" x-classes="text-error">'], true)) {
            continue;
        }

        expect($template)->toContain($snippet);
    }
})->with(array_values(AppServiceProvider::ACCESSIBLE_MARY_COMPONENTS));

it('registers the accessible components under their Mary aliases', function () {
    $aliases = Blade::getClassComponentAliases();

    foreach (AppServiceProvider::ACCESSIBLE_MARY_COMPONENTS as $alias => $component) {
        expect($aliases[config('mary.prefix').$alias])->toBe($component);
    }

    expect($aliases['mary-button'])->toBe(AppServiceProvider::ACCESSIBLE_MARY_COMPONENTS['button'])
        ->and($aliases['mary-modal'])->toBe(AppServiceProvider::ACCESSIBLE_MARY_COMPONENTS['modal']);
});

it('names an input by its label and links its hint', function () {
    $html = renderWithErrors('<x-input label="Callsign" wire:model="call_sign" hint="As licensed" required />');

    preg_match('/<legend id="([^"]+)-label"/', $html, $legend);
    $uuid = $legend[1];

    expect($html)
        ->toContain('aria-labelledby="'.$uuid.'-label"')
        ->toContain('aria-describedby="'.$uuid.'-hint"')
        ->toContain('id="'.$uuid.'-hint"')
        ->toContain('<span class="text-error" aria-hidden="true">*</span>')
        ->not->toContain('aria-invalid');
});

it('marks an input invalid and links its error', function () {
    $html = renderWithErrors('<x-input label="Callsign" wire:model="call_sign" />', ['call_sign' => 'Enter a callsign.']);

    preg_match('/<legend id="([^"]+)-label"/', $html, $legend);
    $uuid = $legend[1];

    expect($html)
        ->toContain('aria-invalid="true"')
        ->toContain('aria-describedby="'.$uuid.'-error"')
        ->toContain('id="'.$uuid.'-error"');
});

it('does not mark a field invalid for another field\'s error', function () {
    $html = renderWithErrors('<x-input label="Name" wire:model="name" />', ['call_sign' => 'Enter a callsign.']);

    expect($html)->not->toContain('aria-invalid');
});

it('keeps an explicit aria-label instead of the legend', function () {
    $html = renderWithErrors('<x-input label="Callsign" aria-label="Search callsigns" wire:model="q" />');

    expect($html)->toContain('aria-label="Search callsigns"')->not->toContain('aria-labelledby');
});

it('names selects and textareas by their label', function (string $blade) {
    $html = renderWithErrors($blade);

    preg_match('/<legend id="([^"]+)-label"/', $html, $legend);

    expect($legend)->not->toBeEmpty()
        ->and($html)->toContain('aria-labelledby="'.$legend[1].'-label"');
})->with([
    'select' => '<x-select label="Band" wire:model="band" :options="[]" />',
    'textarea' => '<x-textarea label="Notes" wire:model="notes" />',
]);

it('links a checkbox error without replacing its label', function () {
    $html = renderWithErrors('<x-checkbox label="Remember me" wire:model="remember" />', ['remember' => 'Required.']);

    expect($html)
        ->toContain('aria-invalid="true"')
        ->toMatch('/aria-describedby="[^"]+-error"/')
        ->not->toContain('aria-labelledby');
});

it('turns the clearable icon into a named button', function () {
    $html = renderWithErrors('<x-input label="Callsign" wire:model.live="call_sign" clearable />');

    expect($html)->toContain('<button type="button" aria-label="Clear Callsign"');
});

it('names an icon-only button by its tooltip', function () {
    $html = renderWithErrors('<x-button icon="phosphor-trash" tooltip="Delete contact" />');

    expect($html)->toContain('aria-label="Delete contact"');
});

it('does not override a labelled button with its tooltip', function () {
    $html = renderWithErrors('<x-button label="Delete" icon="phosphor-trash" tooltip="Delete contact" />');

    expect($html)->not->toContain('aria-label="Delete contact"');
});

it('keeps a responsive button label for screen readers', function () {
    $html = renderWithErrors('<x-button label="Export" icon="phosphor-download" responsive />');

    expect($html)->toContain('sr-only lg:not-sr-only lg:block')->not->toContain('hidden lg:block');
});

it('names a modal by its title and labels its close button', function () {
    $html = renderWithErrors('<x-modal id="confirm-it" title="Edit contact">Body</x-modal>');

    expect($html)
        ->toContain('aria-modal="true"')
        ->toContain('aria-label="Edit contact"')
        ->toContain('aria-label="Close"');
});

it('hides decorative icons and keeps named ones', function () {
    expect(renderWithErrors('<x-icon name="phosphor-house" />'))->toContain('aria-hidden="true"')
        ->and(renderWithErrors('<x-icon name="phosphor-house" aria-label="Home" />'))->not->toContain('aria-hidden');
});

it('keeps the sidebar out of the main landmark', function () {
    $html = renderWithErrors(<<<'BLADE'
        <x-main>
            <x-slot:sidebar drawer="main-drawer" collapsible>Menu</x-slot:sidebar>
            <x-slot:content>Page</x-slot:content>
        </x-main>
        BLADE);

    expect(substr_count($html, '<main'))->toBe(1)
        ->and($html)->toMatch('/<main id="main-content" tabindex="-1"[^>]*>\s*Page\s*<\/main>/')
        ->and($html)->toContain('role="button" tabindex="0"')
        ->and($html)->toContain('x-bind:aria-expanded')
        ->and($html)->toContain('[&_.menu_a>.mary-hideable]:!sr-only');
});

it('keeps the menu a valid list', function () {
    $html = renderWithErrors('<x-menu><x-menu-separator title="Logging" /></x-menu>');

    expect($html)->toContain('<li aria-hidden="true" class="pointer-events-none"><hr');
});

it('renders a header title at the requested heading level', function () {
    $html = renderWithErrors('<x-header heading-level="2" title="Recent Visitors" subtitle="Who came by" />');

    expect($html)
        ->toMatch('/<h2 [^>]*>\s*(<!--.*?-->\s*)*<span[^>]*>Recent Visitors<\/span>/s')
        ->not->toContain('heading-level')
        ->toContain('text-base-content/70 text-sm mt-1');
});

it('gives toggles with the same props their own ids', function () {
    $html = renderWithErrors('<x-toggle wire:model="notify_a" aria-label="A" /><x-toggle wire:model="notify_b" aria-label="B" />');

    preg_match_all('/<input\s+id="([^"]+)"/', $html, $ids);

    expect($ids[1])->toHaveCount(2)
        ->and($ids[1][0])->not->toBe($ids[1][1]);
});

it('lets keyboards reach menu items that only run an action', function () {
    $html = renderWithErrors('<x-menu><x-menu-item title="Delete" wire:click="delete" /><x-menu-item title="Home" link="/" /></x-menu>');

    expect(substr_count($html, 'role="button" tabindex="0"'))->toBe(1);
});
