<?php

uses()->group('unit', 'docker');

/**
 * The port Octane/Caddy listens on inside the container. It must stay above
 * 1024 because the image runs as www-data with no capabilities.
 */
const CONTAINER_HTTP_PORT = 8000;

test('every deploy path agrees on the unprivileged container http port', function () {
    $port = CONTAINER_HTTP_PORT;

    expect(file_get_contents(base_path('docker/Caddyfile')))->toStartWith(":{$port} {")
        ->and(file_get_contents(base_path('docker/supervisord.conf')))->toContain("octane:frankenphp --host=0.0.0.0 --port={$port} ")
        ->and(file_get_contents(base_path('Dockerfile')))
        ->toContain("EXPOSE {$port}\n")
        ->toContain("http://127.0.0.1:{$port}/up")
        ->and(file_get_contents(base_path('docker-compose.yml')))->toContain('"${APP_PORT:-80}:'.$port.'"')
        ->and(file_get_contents(base_path('charts/fd-commander/templates/deployment.yaml')))->toMatch("/name: http\n\s+containerPort: {$port}\n/");
});

test('the image runs as www-data without root or capabilities', function () {
    $dockerfile = file_get_contents(base_path('Dockerfile'));
    $supervisord = file_get_contents(base_path('docker/supervisord.conf'));
    $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));
    $chartValues = file_get_contents(base_path('charts/fd-commander/values.yaml'));

    expect($dockerfile)
        ->toContain("\nUSER www-data\n")
        ->toContain('setcap -r /usr/local/bin/frankenphp')
        ->and($supervisord)->not->toContain('user=')
        ->and($supervisord)->not->toContain('/var/run/')
        ->and($entrypoint)->not->toMatch('/^\s*chown /m')
        ->and($chartValues)
        ->toContain('runAsNonRoot: true')
        ->toContain('allowPrivilegeEscalation: false')
        ->not->toContain('add:');
});

test('the image build is not affected by local caches or sh brace syntax', function () {
    expect(file_get_contents(base_path('.dockerignore')))->toContain("\nbootstrap/cache/*.php\n")
        ->and(file_get_contents(base_path('Dockerfile')))->not->toMatch('/^\s*(RUN|&&).*mkdir[^\n]*\{/m');
});
