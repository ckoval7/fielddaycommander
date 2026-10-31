<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Tests\Browser\Support\FieldDayScene;

it('moves between recent QSOs with the arrow, Home and End keys', function () {
    $scene = FieldDayScene::create();
    $this->actingAs($scene->admin);

    $page = visit($scene->path('/logging/session/{session}'))->on()->desktop()->resize(1280, 800);

    $focused = '() => document.activeElement?.getAttribute("aria-label")';
    $visible = '[...document.querySelectorAll("[x-roving-focus] [data-roving-item]")].filter((i) => i.offsetParent !== null)';
    $items = $page->script("() => {$visible}.map((i) => i.getAttribute('aria-label'))");
    expect(count($items))->toBeGreaterThan(2);

    $page->script("() => {$visible}[0].focus()");
    expect($page->script($focused))->toBe($items[0]);

    $page->keys('[x-roving-focus] [data-roving-item]:focus', 'ArrowDown');
    expect($page->script($focused))->toBe($items[1]);

    $page->keys('[x-roving-focus] [data-roving-item]:focus', 'ArrowRight');
    expect($page->script($focused))->toBe($items[2]);

    $page->keys('[x-roving-focus] [data-roving-item]:focus', 'ArrowUp');
    expect($page->script($focused))->toBe($items[1]);

    $page->keys('[x-roving-focus] [data-roving-item]:focus', 'End');
    expect($page->script($focused))->toBe(end($items));

    $page->keys('[x-roving-focus] [data-roving-item]:focus', 'Home');
    expect($page->script($focused))->toBe($items[0]);
});
