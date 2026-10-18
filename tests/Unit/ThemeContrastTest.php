<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

const SEMANTIC_COLORS = ['primary', 'secondary', 'accent', 'neutral', 'info', 'success', 'warning', 'error'];

/**
 * Extracts the custom properties of the named daisyUI theme block in app.css.
 *
 * @return array<string, string>
 */
function daisyThemeTokens(string $theme): array
{
    $css = file_get_contents(resource_path('css/app.css'));
    preg_match('/@plugin "daisyui\/theme" \{\s*name: "'.$theme.'";(.*?)\n\}/s', $css, $block);

    expect($block)->not->toBeEmpty("daisyUI theme '{$theme}' not found");

    preg_match_all('/(--[\w-]+):\s*([^;]+);/', $block[1], $matches);

    return array_combine($matches[1], $matches[2]);
}

/**
 * Reads an `hsl(h, s%, l%)` custom property from colors.css for the given theme.
 */
function colorsCssToken(string $theme, string $token): string
{
    $css = file_get_contents(resource_path('css/colors.css'));
    $darkStart = strpos($css, '[data-theme="dark"] {');
    $block = $theme === 'dark' ? substr($css, $darkStart) : substr($css, 0, $darkStart);
    preg_match('/'.preg_quote($token, '/').':\s*([^;]+);/', $block, $matches);

    expect($matches)->not->toBeEmpty("{$token} not found");

    return $matches[1];
}

/**
 * Relative luminance of an `oklch(L% C H)` or `hsl(H, S%, L%)` colour.
 */
function relativeLuminance(string $color): float
{
    if (preg_match('/oklch\(([\d.]+)%\s+([\d.]+)\s+([\d.]+)\)/', $color, $m)) {
        $lightness = (float) $m[1] / 100;
        $a = (float) $m[2] * cos(deg2rad((float) $m[3]));
        $b = (float) $m[2] * sin(deg2rad((float) $m[3]));

        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $mm = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $linear = [
            4.0767416621 * $l - 3.3077115913 * $mm + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $mm - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $mm + 1.7076147010 * $s,
        ];
    } elseif (preg_match('/hsl\(([\d.]+),\s*([\d.]+)%,\s*([\d.]+)%\)/', $color, $m)) {
        [$hue, $saturation, $lightness] = [(float) $m[1], (float) $m[2] / 100, (float) $m[3] / 100];
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $offset = $lightness - $chroma / 2;

        $linear = array_map(function (int $n) use ($hue, $chroma, $offset): float {
            $k = fmod($n + $hue / 30, 12);
            $value = $offset + $chroma * (0.5 - max(-0.5, min($k - 3, 9 - $k, 0.5)));

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, [0, 8, 4]);
    } else {
        throw new InvalidArgumentException("Unsupported colour: {$color}");
    }

    [$red, $green, $blue] = array_map(fn (float $channel): float => max(0.0, min(1.0, $channel)), $linear);

    return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
}

function contrastRatio(string $colorA, string $colorB): float
{
    $luminanceA = relativeLuminance($colorA);
    $luminanceB = relativeLuminance($colorB);

    return (max($luminanceA, $luminanceB) + 0.05) / (min($luminanceA, $luminanceB) + 0.05);
}

test('semantic text colours meet WCAG AA on base backgrounds', function (string $theme) {
    $tokens = daisyThemeTokens($theme);

    foreach (SEMANTIC_COLORS as $color) {
        foreach (['--color-base-100', '--color-base-200'] as $base) {
            expect(contrastRatio($tokens["--color-{$color}"], $tokens[$base]))
                ->toBeGreaterThanOrEqual(4.5, "{$theme} {$color} on {$base}");
        }
    }
})->with(['light', 'dark']);

test('strong-content text meets WCAG AA on strong semantic fills', function (string $theme) {
    $tokens = daisyThemeTokens($theme);

    foreach (SEMANTIC_COLORS as $color) {
        expect(contrastRatio($tokens["--color-{$color}-strong"], $tokens["--color-{$color}-strong-content"]))
            ->toBeGreaterThanOrEqual(4.5, "{$theme} {$color}-strong-content on {$color}-strong");
    }

    expect(contrastRatio(colorsCssToken($theme, '--color-club-strong'), 'oklch(100% 0 0)'))
        ->toBeGreaterThanOrEqual(4.5, "{$theme} white on club-strong");
})->with(['light', 'dark']);

test('content colours meet WCAG AA on their semantic fills', function (string $theme) {
    $tokens = daisyThemeTokens($theme);

    foreach (SEMANTIC_COLORS as $color) {
        expect(contrastRatio($tokens["--color-{$color}-content"], $tokens["--color-{$color}"]))
            ->toBeGreaterThanOrEqual(4.5, "{$theme} {$color}-content on {$color}");
    }
})->with(['light', 'dark']);

test('scoring chips meet WCAG AA on their fills', function (string $theme) {
    foreach (['verified-fill', 'amber-fill'] as $fill) {
        expect(contrastRatio(colorsCssToken($theme, "--score-{$fill}"), colorsCssToken($theme, "--score-on-{$fill}")))
            ->toBeGreaterThanOrEqual(4.5, "{$theme} score {$fill}");
    }
})->with(['light', 'dark']);

test('colors.css leaves daisyUI semantic tokens to the theme', function () {
    $css = file_get_contents(resource_path('css/colors.css'));

    expect($css)->not->toMatch('/--color-(primary|secondary|accent|neutral|info|success|warning|error)(-content|-strong)?:/');
});

test('daisyUI built-in themes are disabled so they cannot outrank the custom themes', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toMatch('/@plugin "daisyui" \{\s*themes: false;\s*\}/');
});

test('app stylesheet beats daisyUI without !important declarations', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->not->toMatch('/:[^;{}]*!important\s*;/');
});

test('checkboxes default to the brand blue fill', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toMatch('/\.checkbox:not\([^)]*\)\s*\{\s*--input-color: var\(--color-primary-strong\);\s*color: white;/')
        ->not->toContain('/* Checkbox styling */');
});

test('tabs use the theme primary for the active tab and readable inactive labels', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toMatch('/\.tabs-border \.tab-active \{\s*color: var\(--color-primary\);/')
        ->toMatch('/\.tabs-border \.tab:not\(\.tab-active, :hover\) \{\s*color: color-mix\(in oklab, var\(--color-base-content\) 70%, transparent\);/')
        ->not->toContain('border-bottom-width: 2px;');
});
