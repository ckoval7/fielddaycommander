<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Symfony\Component\Yaml\Yaml;

/*
 * Hosted mode is for a hosting service running one Helm release per club.
 * Every self-hosted path keeps it off, and the hosted preset keeps a club
 * from getting developer tools, listener ports or a shared database user.
 */

test('self-hosted paths leave hosted mode off', function () {
    expect(file_get_contents(base_path('.env.example')))->toContain("\nHOSTED_MODE=false\n")
        ->and(file_get_contents(base_path('deploy.sh')))->toContain('set_env "HOSTED_MODE" "false"')
        ->and(Yaml::parseFile(base_path('charts/fd-commander/values.yaml'))['hosted']['enabled'])->toBeFalse();
});

test('the entrypoint applies the bootstrap file only in hosted mode', function () {
    $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

    expect($entrypoint)->toMatch('/if \[\[ "\$\{HOSTED_MODE:-false\}" = "true" \]\]; then\n.*?php artisan tenant:bootstrap --from="\$BOOTSTRAP_FILE"/s')
        // After migrations and seeders, before the config is cached.
        ->and(strpos($entrypoint, 'tenant:bootstrap'))->toBeGreaterThan(strpos($entrypoint, 'SystemAdminSeeder'))
        ->and(strpos($entrypoint, 'tenant:bootstrap'))->toBeLessThan(strpos($entrypoint, 'php artisan optimize'));
});

test('the chart mounts the bootstrap file where the app looks for it', function () {
    $deployment = file_get_contents(base_path('charts/fd-commander/templates/deployment.yaml'));
    $config = require config_path('hosted.php');

    expect($config['bootstrap_file'])->toBe('/run/fd-commander/bootstrap/tenant.json')
        ->and($deployment)->toContain('mountPath: /run/fd-commander/bootstrap')
        ->and($deployment)->toContain('path: tenant.json')
        ->and($deployment)->toContain('optional: true');
});

test('the hosted preset turns off what a shared cluster cannot give each club', function () {
    $values = Yaml::parseFile(base_path('charts/fd-commander/values-hosted.yaml'));

    expect($values['hosted']['enabled'])->toBeTrue()
        ->and($values['app']['developerMode'])->toBeFalse()
        ->and($values['externalLoggers']['enabled'])->toBeFalse()
        ->and($values['mysql']['enabled'])->toBeFalse()
        ->and($values['redis']['enabled'])->toBeFalse()
        ->and($values['networkPolicy']['enabled'])->toBeTrue()
        ->and($values['ingress']['tls']['enabled'])->toBeFalse()
        ->and($values['ingress']['annotations']['traefik.ingress.kubernetes.io/router.tls'])->toBe('true');
});
