<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The N3FJP server listens on the port the admin UI shows, 1000 by default,
 * which is below 1024. Every deploy path has to let the unprivileged app
 * bind it without root and without a port redirect.
 */

test('docker publishes the N3FJP port over TCP and lets the app bind it', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));

    expect($compose)->toContain('"${N3FJP_PORT:-1000}:${N3FJP_PORT:-1000}/tcp"')
        ->and($compose)->toContain('net.ipv4.ip_unprivileged_port_start: 0')
        ->and(file_get_contents(base_path('docker/setup.sh')))->toContain('# set_env "N3FJP_PORT" "1000"');
});

test('helm publishes the N3FJP port as a hostPort with the low-port sysctl', function () {
    $values = file_get_contents(base_path('charts/fd-commander/values.yaml'));
    $deployment = file_get_contents(base_path('charts/fd-commander/templates/deployment.yaml'));

    expect($values)->toMatch("/  n3fjp:\n    enabled: true\n    port: 1000\n/")
        ->and($deployment)->toContain('hostPort: {{ .Values.externalLoggers.n3fjp.port }}')
        ->and($deployment)->toContain('name: net.ipv4.ip_unprivileged_port_start')
        ->and($deployment)->toContain('type: Recreate')
        ->and(file_get_contents(base_path('charts/fd-commander/templates/configmap.yaml')))
        ->toContain('N3FJP_PORT: {{ .Values.externalLoggers.n3fjp.port | quote }}');
});

test('bare-metal units that start listeners may bind low ports, and only they', function (string $script) {
    $contents = file_get_contents(base_path($script));
    preg_match('/^write_unit_hardening\(\) \{\n(.*?)\n\}/ms', $contents, $function);

    expect($function[1] ?? '')
        ->toContain('"$unit" == "fdcommander" || "$unit" == "fdcommander-scheduler"')
        ->toContain('CapabilityBoundingSet=CAP_NET_BIND_SERVICE')
        ->toContain('AmbientCapabilities=CAP_NET_BIND_SERVICE')
        ->toContain('${extra_paths}${bind_caps}');
})->with(['deploy.sh', 'update.sh']);
