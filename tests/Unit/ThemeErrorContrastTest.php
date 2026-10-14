<?php

/**
 * Reads an `hsl(h, s%, l%)` custom property from a block of CSS.
 *
 * @return array{0: float, 1: float, 2: float}
 */
function readHslToken(string $css, string $token): array
{
    preg_match('/'.preg_quote($token, '/').':\s*hsl\(([\d.]+),\s*([\d.]+)%,\s*([\d.]+)%\)/', $css, $matches);

    expect($matches)->not->toBeEmpty("{$token} not found");

    return [(float) $matches[1], (float) $matches[2] / 100, (float) $matches[3] / 100];
}

/**
 * @param  array{0: float, 1: float, 2: float}  $hsl
 */
function hslLuminance(array $hsl): float
{
    [$hue, $saturation, $lightness] = $hsl;
    $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
    $offset = $lightness - $chroma / 2;

    $channel = function (int $n) use ($hue, $chroma, $offset): float {
        $k = fmod($n + $hue / 30, 12);
        $value = $offset + $chroma * (0.5 - max(-0.5, min($k - 3, 9 - $k, 0.5)));

        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $channel(0) + 0.7152 * $channel(8) + 0.0722 * $channel(4);
}

function contrastRatio(float $luminanceA, float $luminanceB): float
{
    return (max($luminanceA, $luminanceB) + 0.05) / (min($luminanceA, $luminanceB) + 0.05);
}

/**
 * @return array{light: string, dark: string}
 */
function themeBlocks(): array
{
    $css = file_get_contents(resource_path('css/colors.css'));
    $darkStart = strpos($css, '[data-theme="dark"] {');

    return ['light' => substr($css, 0, $darkStart), 'dark' => substr($css, $darkStart)];
}

/** Luminance of daisyUI's base-100 (white) and dark base-100 (#1d232a). */
const LIGHT_BASE_LUMINANCE = 1.0;
const DARK_BASE_LUMINANCE = 0.0159;

test('error text meets WCAG AA on the page background', function (string $theme, float $baseLuminance) {
    $error = hslLuminance(readHslToken(themeBlocks()[$theme], '--color-error'));

    expect(contrastRatio($error, $baseLuminance))->toBeGreaterThanOrEqual(4.5);
})->with([
    'light' => ['light', LIGHT_BASE_LUMINANCE],
    'dark' => ['dark', DARK_BASE_LUMINANCE],
]);

test('text on solid error fills meets WCAG AA', function (string $theme) {
    $block = themeBlocks()[$theme];
    $content = hslLuminance(readHslToken($block, '--color-error-content'));
    $fill = hslLuminance(readHslToken($block, '--color-error-strong'));

    expect(contrastRatio($content, $fill))->toBeGreaterThanOrEqual(4.5);
})->with(['light', 'dark']);
