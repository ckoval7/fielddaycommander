<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Support\IconSprite;

it('renders icons inline outside a frame', function () {
    $html = (new IconSprite)->render('phosphor-house', 'w-5 h-5', []);

    expect($html)->toContain('<path')->not->toContain('<use');
});

it('renders a reference and collects the shape once inside a frame', function () {
    $sprite = new IconSprite;
    $sprite->begin();

    $first = $sprite->render('phosphor-house', 'w-5 h-5', ['@click' => 'n > 1 && go()']);
    $second = $sprite->render('phosphor-house', 'w-4 h-4', []);
    $symbols = $sprite->end();

    expect($first)
        ->toContain('<use href="#fdc-i-phosphor-house"/>')
        ->toContain('viewBox="0 0 256 256"')
        ->toContain('@click="n > 1 && go()"')
        ->not->toContain('<path');
    expect($second)->toContain('class="w-4 h-4"');
    expect($symbols)->toHaveCount(1)->toHaveKey('fdc-i-phosphor-house');
    expect($symbols['fdc-i-phosphor-house'])->toStartWith('<symbol id="fdc-i-phosphor-house"><path');
});

it('keeps titled icons inline', function () {
    $sprite = new IconSprite;
    $sprite->begin();

    expect($sprite->render('phosphor-house', '', ['title' => 'Home']))->toContain('<title>Home</title>')->not->toContain('<use');
});

it('places a component sprite inside its root', function () {
    $sprite = new IconSprite;
    $sprite->begin();
    $icon = $sprite->render('phosphor-house', '', []);

    $html = IconSprite::appendToRoot("<div wire:id=\"x\">$icon</div>\n", $sprite->end());

    expect($html)->toMatch('~^<div wire:id="x"><svg[^>]*><use href="#fdc-i-phosphor-house"/></svg><svg [^>]*style="display:none"><symbol id="fdc-i-phosphor-house">.*</symbol></svg></div>\n$~s');
});

it('places a page sprite before the closing body tag', function () {
    $sprite = new IconSprite;
    $sprite->begin();
    $icon = $sprite->render('phosphor-house', '', []);

    $html = IconSprite::appendToBody("<html><body>$icon</body></html>", $sprite->endAll());

    expect($html)->toMatch('~<use href="#fdc-i-phosphor-house"/></svg><svg [^>]*style="display:none"><symbol .*</svg></body></html>$~s');
});

it('inlines the shapes again when there is nowhere to put the sprite', function () {
    $sprite = new IconSprite;
    $sprite->begin();
    $icon = $sprite->render('phosphor-house', '', []);

    $html = IconSprite::appendToBody("<span>$icon</span>", $sprite->endAll());

    expect($html)->toContain('<path')->not->toContain('<use')->not->toContain('<symbol');
});

it('collects frames a failed render left open', function () {
    $sprite = new IconSprite;
    $sprite->begin();
    $sprite->render('phosphor-house', '', []);
    $sprite->begin();
    $sprite->render('phosphor-x', '', []);

    expect(array_keys($sprite->endAll()))->toBe(['fdc-i-phosphor-house', 'fdc-i-phosphor-x']);
    expect($sprite->end())->toBe([]);
});
