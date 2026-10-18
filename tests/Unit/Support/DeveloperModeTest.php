<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Support\DeveloperMode;

test('developer mode and role overrides follow the developer config outside production', function (bool $configured) {
    config(['developer.enabled' => $configured]);

    expect(DeveloperMode::isEnabled())->toBe($configured)
        ->and(DeveloperMode::allowsRoleOverride())->toBe($configured);
})->with([
    'enabled' => [true],
    'disabled' => [false],
]);

test('developer mode and role overrides are off in production by default', function () {
    config(['developer.enabled' => true, 'developer.allow_in_production' => false]);
    $this->app['env'] = 'production';

    expect(DeveloperMode::isEnabled())->toBeFalse()
        ->and(DeveloperMode::allowsRoleOverride())->toBeFalse();
});

test('production can opt in to developer mode and role overrides', function () {
    config(['developer.enabled' => true, 'developer.allow_in_production' => true]);
    $this->app['env'] = 'production';

    expect(DeveloperMode::isEnabled())->toBeTrue()
        ->and(DeveloperMode::allowsRoleOverride())->toBeTrue();
});
