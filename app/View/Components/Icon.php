<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\Support\IconSprite;
use BladeUI\Icons\Factory as BladeIconsFactory;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Mary\View\Components\Icon as MaryIcon;

/**
 * Prefix-aware replacement for Mary UI's <x-icon> component.
 *
 * Mary's default implementation hardcodes a `heroicon-` prefix onto any
 * name that does not contain a `.`. That prevents names with an explicit
 * icon-set prefix (e.g. `phosphor-house`) from resolving, because Mary
 * rewrites them to `heroicon-phosphor-house` before handing them to
 * Blade Icons.
 *
 * This subclass keeps Mary's `.`-as-separator shorthand intact, but when
 * the name already starts with a registered Blade Icons set prefix we
 * pass it through untouched so prefix-based routing works. Unprefixed
 * names (and the `o-*` / `s-*` short forms) continue to receive the
 * `heroicon-` prefix exactly as before, preserving backward
 * compatibility with existing Heroicon usage throughout the app.
 *
 * Icons render through IconSprite, so a page carries each icon's shape once
 * and repeats only a short `<use>` reference.
 *
 * Icons are hidden from screen readers unless they are given a name
 * (`aria-label`, `aria-labelledby`, `title`) or a `role`, since nearly all
 * of them sit next to text that already says what they mean.
 */
class Icon extends MaryIcon
{
    public function icon(): string|Stringable
    {
        $name = Str::of($this->name);

        if ($name->contains('.')) {
            return $name->replace('.', '-');
        }

        if ($this->hasRegisteredPrefix($this->name)) {
            return $name;
        }

        return "heroicon-{$this->name}";
    }

    /**
     * The icon markup, as a sprite reference where IconSprite has a frame open.
     */
    public function svg(): string
    {
        $named = $this->attributes->hasAny(['aria-label', 'aria-labelledby', 'title', 'role']);

        $attributes = $this->attributes->merge($named ? [] : ['aria-hidden' => 'true'])->class([
            'inline flex-shrink-0',
            'w-5 h-5' => ! Str::contains($this->attributes->get('class') ?? '', ['w-', 'h-']),
        ])->getAttributes();

        $class = $attributes['class'] ?? '';
        unset($attributes['class']);

        return app(IconSprite::class)->render((string) $this->icon(), $class, $attributes);
    }

    public function render(): View|Closure|string
    {
        return <<<'BLADE'
                @if(strlen($label ?? '') > 0)
                    <div class="inline-flex items-center gap-1">
                @endif
                    {!! $svg() !!}

                @if(strlen($label ?? '') > 0)
                        <div class="{{ $labelClasses() }}" {{ $attributes->whereStartsWith('@') }}>
                            {{ $label }}
                        </div>
                    </div>
                @endif
            BLADE;
    }

    protected function hasRegisteredPrefix(string $name): bool
    {
        $prefix = Str::before($name, '-');

        if ($prefix === '' || $prefix === $name) {
            return false;
        }

        /** @var BladeIconsFactory $factory */
        $factory = app(BladeIconsFactory::class);

        foreach ($factory->all() as $set) {
            if (($set['prefix'] ?? null) === $prefix) {
                return true;
            }
        }

        return false;
    }
}
