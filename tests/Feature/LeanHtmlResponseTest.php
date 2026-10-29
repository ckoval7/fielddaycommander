<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Logbook\LogbookBrowser;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    Setting::set('setup_completed', 'true');

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);
});

it('serves pages without template indentation or comments', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('logbook.index'))->assertOk()->getContent();

    $markup = preg_replace('~<(script|style)\b.*?</\1>~s', '', $html);

    expect($markup)
        ->not->toMatch('~\n[ \t]+<~')
        ->toContain('<!--[if BLOCK]><![endif]-->')
        ->not->toMatch('~<!--(?!\[| Livewire (Styles|Scripts) -->)~');
});

it('serves every icon on a page from a sprite', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('logbook.index'))->assertOk()->getContent();

    preg_match_all('~<use href="#(fdc-i-[\w-]+)"/>~', $html, $references);
    preg_match_all('~<symbol id="(fdc-i-[\w-]+)">~', $html, $symbols);

    expect($references[1])->not->toBeEmpty();
    expect(array_diff(array_unique($references[1]), $symbols[1]))->toBeEmpty();
    expect(substr_count($html, '<path'))->toBeLessThan(count($references[1]));
});

it('keeps each Livewire component\'s icons inside its own root', function () {
    $this->actingAs(User::factory()->create());

    $html = Livewire::test(LogbookBrowser::class)->html();

    preg_match_all('~<use href="#(fdc-i-[\w-]+)"/>~', $html, $references);
    preg_match_all('~<symbol id="(fdc-i-[\w-]+)">~', $html, $symbols);

    expect($references[1])->not->toBeEmpty();
    expect(array_diff(array_unique($references[1]), $symbols[1]))->toBeEmpty();
    expect($html)->toMatch('~</symbol></svg></div>\s*$~');
});
