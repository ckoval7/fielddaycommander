<?php

namespace App\View\Components;

use BladeUI\Icons\Factory as BladeIconsFactory;
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
