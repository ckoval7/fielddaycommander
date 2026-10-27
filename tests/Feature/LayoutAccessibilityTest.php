<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Screen reader and keyboard essentials shared by every page: a skip link,
 * landmarks, the announcement regions and a quiet header.
 */
test('layouts start with a skip link to the main content', function (string $layout) {
    $html = view($layout, ['slot' => ''])->render();

    expect($html)
        ->toContain('href="#main-content"')
        ->toContain('Skip to main content')
        ->and(strpos($html, 'Skip to main content'))->toBeLessThan(strpos($html, 'id="main-content"'));
})->with(['components.layouts.app', 'components.layouts.guest']);

test('layouts have the announcement regions', function (string $layout) {
    $html = view($layout, ['slot' => ''])->render();

    expect($html)
        ->toContain('<output id="fdc-announce-polite" class="sr-only" aria-live="polite"')
        ->toContain('id="fdc-announce-assertive" class="sr-only" role="alert" aria-live="assertive"');
})->with(['components.layouts.app', 'components.layouts.guest']);

test('the app layout keeps navigation out of the main landmark', function () {
    $html = view('components.layouts.app', ['slot' => 'Page body'])->render();

    $mainStart = strpos($html, '<main id="main-content"');
    $mainEnd = strpos($html, '</main>', $mainStart);
    $main = substr($html, $mainStart, $mainEnd - $mainStart);

    expect(substr_count($html, '<main'))->toBe(1)
        ->and($main)->toContain('Page body')
        ->and($main)->not->toContain('<nav aria-label="Main">')
        ->and($html)->toContain('<nav aria-label="Main">');
});

test('the desktop header is a header landmark', function () {
    $html = view('components.layouts.app', ['slot' => ''])->render();

    expect($html)->toMatch('/<header\s+class="hidden lg:block sticky/');
});

test('the mobile menu button reports and controls the drawer', function () {
    $html = view('components.layouts.app', ['slot' => ''])->render();

    expect($html)
        ->toContain('aria-label="Navigation menu"')
        ->toContain('aria-controls="main-drawer-panel"')
        ->toContain('id="main-drawer-panel"')
        ->toContain('@keydown.escape.window');
});

test('the event countdown is not a live region', function () {
    $view = file_get_contents(resource_path('views/livewire/components/event-countdown.blade.php'));

    expect($view)->not->toContain('aria-live');
});

test('toasts stay up long enough to read', function () {
    $html = view('components.layouts.app', ['slot' => ''])->render();

    expect($html)->toContain('timeout: data.timeout || 5000');
});

test('guest pages are titled and headed by their page title', function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<title>Sign In - '.config('app.name').'</title>', false)
        ->assertSee('<h1 class="text-2xl font-bold mb-6">Sign In</h1>', false);
});

test('sign-in fields are named by their labels', function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    $html = $this->get(route('login'))->assertOk()->getContent();

    preg_match_all('/<legend id="([^"]+)-label"/', $html, $legends);

    expect($legends[1])->toHaveCount(2);

    foreach ($legends[1] as $uuid) {
        expect($html)->toContain('aria-labelledby="'.$uuid.'-label"');
    }
});

test('the header controls have names', function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    $user = User::factory()->create(['call_sign' => 'K1ABC']);

    $html = $this->actingAs($user)->get(route('profile'))->getContent();

    expect($html)
        ->toContain('aria-label="Account menu for K1ABC"')
        ->toContain('<span class="sr-only">Notifications</span>');
});

test('guest pages with their own heading do not get a second one', function () {
    $html = Blade::render('<x-layouts.guest own-heading><x-slot:title>Step 1</x-slot:title><h1>Step 1</h1></x-layouts.guest>');

    expect(substr_count($html, '<h1'))->toBe(1);
});
