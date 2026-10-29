<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

/**
 * Collects icon shapes into SVG sprites so a page carries each icon once.
 *
 * Inside a frame, an icon renders as `<svg …><use href="#fdc-i-name"/></svg>`
 * and its shape is kept for the frame's sprite. Outside any frame it renders
 * inline as before. Frames are opened for each Livewire component render
 * (the sprite goes inside the component's root, so a morph never strands a
 * reference) and for full HTML page requests (the sprite goes before
 * `</body>`, covering the layout). Bound with scoped() for Octane.
 */
class IconSprite
{
    private const ID_PREFIX = 'fdc-i-';

    /**
     * Matches a rendered icon: the root's attributes and its inner shapes.
     * Attribute values may contain `>` (Alpine expressions), so quoted
     * values are consumed whole.
     */
    private const SVG_PATTERN = '~^\s*<svg\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*)</svg>\s*$~s';

    /**
     * Open frames, innermost last, each mapping symbol id to its markup.
     *
     * @var list<array<string, string>>
     */
    private array $frames = [];

    public function begin(): void
    {
        $this->frames[] = [];
    }

    /**
     * Close the innermost frame.
     *
     * @return array<string, string> Symbol markup keyed by id.
     */
    public function end(): array
    {
        return array_pop($this->frames) ?? [];
    }

    /**
     * Close every open frame, including any a failed render left open.
     *
     * @return array<string, string> Symbol markup keyed by id.
     */
    public function endAll(): array
    {
        $symbols = array_merge([], ...$this->frames);
        $this->frames = [];

        return $symbols;
    }

    /**
     * Render an icon, as a sprite reference when a frame is open.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function render(string $name, string $class, array $attributes): string
    {
        $html = svg($name, $class, $attributes)->toHtml();

        if ($this->frames === [] || array_key_exists('title', $attributes)) {
            return $html;
        }

        if (preg_match(self::SVG_PATTERN, $html, $matches) !== 1) {
            return $html;
        }

        $id = self::ID_PREFIX.$name;
        $frame = array_key_last($this->frames);
        $this->frames[$frame][$id] ??= '<symbol id="'.e($id).'">'.$matches[2].'</symbol>';

        return '<svg'.$matches[1].'><use href="#'.e($id).'"/></svg>';
    }

    /**
     * Put a frame's sprite just inside the closing tag of an HTML root, or
     * inline the shapes again when there is no root to put it in.
     *
     * @param  array<string, string>  $symbols
     */
    public static function appendToRoot(string $html, array $symbols): string
    {
        if ($symbols === []) {
            return $html;
        }

        $withSprite = preg_replace('~(</[a-zA-Z][\w-]*>\s*)$~', self::sprite($symbols).'$1', $html, 1, $count);

        return $count === 1 ? $withSprite : self::inline($html, $symbols);
    }

    /**
     * Put a frame's sprite before `</body>`, or inline the shapes again
     * when the response isn't a full page.
     *
     * @param  array<string, string>  $symbols
     */
    public static function appendToBody(string $html, array $symbols): string
    {
        if ($symbols === []) {
            return $html;
        }

        $position = strripos($html, '</body>');

        if ($position === false) {
            return self::inline($html, $symbols);
        }

        return substr_replace($html, self::sprite($symbols), $position, 0);
    }

    /**
     * @param  array<string, string>  $symbols
     */
    private static function sprite(array $symbols): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="display:none">'.implode('', $symbols).'</svg>';
    }

    /**
     * Swap sprite references back for the shapes they point at.
     *
     * @param  array<string, string>  $symbols
     */
    private static function inline(string $html, array $symbols): string
    {
        $replacements = [];

        foreach ($symbols as $id => $symbol) {
            $shapes = preg_replace('~^<symbol\b[^>]*>(.*)</symbol>$~s', '$1', $symbol);
            $replacements['<use href="#'.e($id).'"/>'] = $shapes;
        }

        return strtr($html, $replacements);
    }
}
