<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Setting;

/**
 * Mobile shell: home-screen install metadata, viewport-height units and
 * safe-area padding for notched phones (the viewport uses viewport-fit=cover).
 */
test('layouts include home-screen install metadata', function (string $layout) {
    $html = view($layout, ['slot' => ''])->render();

    expect($html)
        ->toContain('<link rel="manifest" href="'.asset('manifest.webmanifest').'">')
        ->toContain('<link rel="apple-touch-icon" href="'.asset('apple-touch-icon.png').'">')
        ->toContain('<meta name="theme-color" content="#ffffff">')
        ->toContain('<meta name="apple-mobile-web-app-capable" content="yes">');
})->with(['components.layouts.app', 'components.layouts.guest']);

test('theme script keeps theme-color in step with the chosen theme', function (string $layout) {
    $html = view($layout, ['slot' => ''])->render();

    expect($html)->toContain(
        "document.querySelector('meta[name=\"theme-color\"]')?.setAttribute('content', theme === 'dark' ? '#1d232a' : '#ffffff')"
    );
})->with(['components.layouts.app', 'components.layouts.guest']);

test('home-screen title uses the site name', function () {
    Setting::set('site_name', 'W1AW Field Day');

    $html = view('components.layouts.app', ['slot' => ''])->render();

    expect($html)->toContain('<meta name="apple-mobile-web-app-title" content="W1AW Field Day">');
});

test('layouts use dynamic viewport height instead of 100vh', function (string $layout) {
    $html = view($layout, ['slot' => ''])->render();

    expect($html)
        ->toContain('<body class="min-h-dvh')
        ->not->toContain('min-h-screen');
})->with(['components.layouts.app', 'components.layouts.guest']);

test('mobile header and footer clear the notch and home indicator', function () {
    $html = view('components.layouts.app', ['slot' => ''])->render();

    expect($html)
        ->toMatch('/<header\s+class="lg:hidden sticky top-0[^"]*pt-\[env\(safe-area-inset-top\)\]/')
        ->toContain('pl-[max(0.75rem,env(safe-area-inset-left))] pr-[max(0.375rem,env(safe-area-inset-right))]')
        ->toContain('pb-[max(1rem,env(safe-area-inset-bottom))]');
});

test('manifest is valid and its icons exist', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)
        ->toHaveKeys(['name', 'short_name', 'start_url', 'display', 'icons'])
        ->and($manifest['display'])->toBe('standalone')
        ->and(collect($manifest['icons'])->pluck('purpose'))->toContain('any', 'maskable');

    foreach ($manifest['icons'] as $icon) {
        $path = public_path(ltrim($icon['src'], '/'));
        expect($path)->toBeFile();

        [$width, $height] = getimagesize($path);
        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }

    expect(public_path('apple-touch-icon.png'))->toBeFile();
});

test('touch-height menu rows keep their labels vertically centered', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toMatch('/\.menu :where\(li:not\(\.menu-title\) > :is\(a, button, summary\)\),\s*\.menu :where\(li:not\(\.menu-title\) > details > summary\) \{[^}]*min-height: 2\.75rem;[^}]*align-content: center;/');
});
