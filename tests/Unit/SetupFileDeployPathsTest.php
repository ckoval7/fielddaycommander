<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Symfony\Component\Yaml\Yaml;

/*
 * Every install path can complete first-time setup from a file and lock
 * SYSTEM, and leaves both off unless asked.
 */

test('SYSTEM stays unlocked unless asked', function () {
    expect(file_get_contents(base_path('.env.example')))->toContain("\nSYSTEM_ACCOUNT_LOCKED=false\n")
        ->and(file_get_contents(base_path('deploy.sh')))->toContain("LOCK_SYSTEM=false\n")
        ->and(file_get_contents(base_path('deploy.sh')))->toContain('set_env "SYSTEM_ACCOUNT_LOCKED" "$LOCK_SYSTEM"')
        ->and(Yaml::parseFile(base_path('charts/fd-commander/values.yaml'))['app']['systemAccountLocked'])->toBeFalse();
});

test('the entrypoint applies a setup file after the seeders and before caching config', function () {
    $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

    expect($entrypoint)->toMatch('/if \[\[ -n "\$\{SETUP_FILE:-\}" \]\]; then\n.*?php artisan setup:apply "\$SETUP_FILE"/s')
        ->and(strpos($entrypoint, 'setup:apply'))->toBeGreaterThan(strpos($entrypoint, 'SystemAdminSeeder'))
        ->and(strpos($entrypoint, 'setup:apply'))->toBeLessThan(strpos($entrypoint, 'php artisan optimize'));
});

test('deploy.sh applies a setup file after the seeders', function () {
    $deploy = file_get_contents(base_path('deploy.sh'));

    expect($deploy)->toContain('--setup-file)   SETUP_FILE="$2"; shift 2 ;;')
        ->and($deploy)->toContain('php artisan setup:apply - < "$SETUP_FILE"')
        ->and(strpos($deploy, 'setup:apply - <'))->toBeGreaterThan(strpos($deploy, '"SystemAdminSeeder"'));
});

test('the chart mounts the setup file where SETUP_FILE points', function () {
    $deployment = file_get_contents(base_path('charts/fd-commander/templates/deployment.yaml'));
    $configmap = file_get_contents(base_path('charts/fd-commander/templates/configmap.yaml'));

    expect($configmap)->toContain('SETUP_FILE: "/run/fd-commander/setup/setup.json"')
        ->and($deployment)->toContain('mountPath: /run/fd-commander/setup')
        ->and($deployment)->toContain('path: setup.json')
        ->and($deployment)->toContain('optional: true');
});
