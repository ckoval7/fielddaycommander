<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\View\Components;

use App\View\Components\Concerns\PatchesMaryTemplate;
use Mary\View\Components\Main as MaryMain;

/**
 * Mary UI's <x-main> with only the page content in the `main` landmark.
 *
 * Mary wraps the sidebar in `<main>` too, so screen reader users jumping to
 * the main content land on the navigation. The content area becomes the
 * `#main-content` landmark that the skip link targets, the sidebar
 * collapse control reports whether the sidebar is expanded, and collapsed
 * menu items keep their names.
 */
class Main extends MaryMain
{
    use PatchesMaryTemplate;

    public const WRAPPER_OPEN = '<main @class(["w-full mx-auto", "max-w-screen-2xl" => !$fullWidth])>';

    public const WRAPPER_CLOSE = '</main>';

    public const CONTENT_OPEN = '<div {{ $content->attributes->class(["drawer-content w-full mx-auto p-5 lg:px-10 lg:py-5"]) }}>';

    public const CONTENT_CLOSE = "{{ \$content }}\n        </div>";

    public const COLLAPSE_TOGGLE = '@click="toggle"';

    public const OVERLAY = 'aria-label="close sidebar" class="drawer-overlay"';

    public const DRAWER_TOGGLE = 'type="checkbox" class="drawer-toggle"';

    public const COLLAPSED_HIDES_TEXT = "'!w-[62px] [&>*_summary::after]:!hidden [&_.mary-hideable]:!hidden";

    public const SERVER_COLLAPSED_HIDES_TEXT = '"w-[62px] [&>*_summary::after]:hidden [&_.mary-hideable]:hidden';

    /**
     * Collapsing hides menu text with display: none, which also removes each
     * link's name. These keep it in the page as screen-reader-only text.
     */
    public const KEEP_MENU_NAMES = '[&_.menu_a>.mary-hideable]:!block [&_.menu_a>.mary-hideable]:!sr-only [&_.menu_summary>.mary-hideable]:!block [&_.menu_summary>.mary-hideable]:!sr-only';

    /**
     * @return array<string, string>
     */
    public static function templateReplacements(): array
    {
        return [
            self::WRAPPER_OPEN => '<div @class(["w-full mx-auto", "max-w-screen-2xl" => !$fullWidth])>',
            self::WRAPPER_CLOSE => '</div>',
            self::CONTENT_OPEN => '<main id="main-content" tabindex="-1" {{ $content->attributes->class(["drawer-content w-full mx-auto p-5 lg:px-10 lg:py-5 focus:outline-none"]) }}>',
            self::CONTENT_CLOSE => "{{ \$content }}\n        </main>",
            self::COLLAPSED_HIDES_TEXT => self::COLLAPSED_HIDES_TEXT.' '.self::KEEP_MENU_NAMES,
            self::SERVER_COLLAPSED_HIDES_TEXT => self::SERVER_COLLAPSED_HIDES_TEXT.' '.str_replace(':!', ':', self::KEEP_MENU_NAMES),
            self::DRAWER_TOGGLE => self::DRAWER_TOGGLE.' aria-hidden="true" tabindex="-1"',
            self::OVERLAY => 'aria-hidden="true" class="drawer-overlay"',
            self::COLLAPSE_TOGGLE => '@click="toggle" x-bind:aria-expanded="(! collapsed).toString()"',
        ];
    }
}
