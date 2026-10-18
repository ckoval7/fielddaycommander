<?php

use App\Support\LocalNetworkPusherServer;
use Laravel\Reverb\Application as ReverbApplication;
use Laravel\Reverb\Contracts\Connection as ReverbConnection;
use Laravel\Reverb\Contracts\WebSocketConnection;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Laravel\Reverb\Protocols\Pusher\Exceptions\InvalidOrigin;
use Laravel\Reverb\Protocols\Pusher\Server as PusherServer;

uses()->group('unit', 'docker');

/**
 * Evaluates config/reverb.php with the given environment variables set and
 * returns the first app's allowed origins.
 *
 * @param  array<string, string|null>  $environment
 * @return array<int, string>
 */
function reverbAllowedOriginsWith(array $environment): array
{
    $previous = [];

    foreach ($environment as $key => $value) {
        $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];

        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    try {
        $config = require config_path('reverb.php');
    } finally {
        foreach ($previous as $key => [$env, $server, $put]) {
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }

            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }

            putenv($put === false ? $key : "{$key}={$put}");
        }
    }

    return $config['apps']['apps'][0]['allowed_origins'];
}

test('reverb lists the app host, its subdomains and configured hosts when APP_URL names a real host', function () {
    expect(reverbAllowedOriginsWith([
        'APP_URL' => 'https://FD.example.org',
        'REVERB_HOST' => 'ws.example.org',
        'TRUSTED_HOSTS' => 'fd.example.net',
        'REVERB_ALLOWED_ORIGINS' => null,
    ]))->toBe(['fd.example.org', '*.fd.example.org', 'ws.example.org', 'fd.example.net']);
});

test('reverb allows any origin while APP_URL is still localhost or every host is trusted', function () {
    expect(reverbAllowedOriginsWith([
        'APP_URL' => 'http://localhost',
        'REVERB_HOST' => null,
        'TRUSTED_HOSTS' => null,
        'REVERB_ALLOWED_ORIGINS' => null,
    ]))->toBe(['*'])
        ->and(reverbAllowedOriginsWith([
            'APP_URL' => 'https://fd.example.org',
            'REVERB_HOST' => null,
            'TRUSTED_HOSTS' => '*',
            'REVERB_ALLOWED_ORIGINS' => null,
        ]))->toBe(['*']);
});

test('reverb allowed origins add to the defaults', function () {
    expect(reverbAllowedOriginsWith([
        'APP_URL' => 'https://fd.example.org',
        'REVERB_HOST' => null,
        'TRUSTED_HOSTS' => null,
        'REVERB_ALLOWED_ORIGINS' => 'fd.example.org, 203.0.113.10',
    ]))->toBe(['fd.example.org', '*.fd.example.org', '203.0.113.10']);
});

/**
 * Runs Reverb's origin check, as bound in the container, for a connection
 * from the given Origin against an app using the given allowed origins.
 *
 * @param  array<int, string>  $allowedOrigins
 */
function reverbAcceptsOrigin(array $allowedOrigins, ?string $origin): bool
{
    $application = new ReverbApplication('id', 'key', 'secret', 60, 30, $allowedOrigins, 10_000);
    $connection = new class(Mockery::mock(WebSocketConnection::class), $application, $origin) extends ReverbConnection
    {
        public function identifier(): string
        {
            return '1';
        }

        public function id(): string
        {
            return '1.1';
        }

        public function send(string $message): void {}

        public function control(string $type = 'ping'): void {}

        public function terminate(): void {}
    };

    app()->instance(ChannelManager::class, Mockery::mock(ChannelManager::class));

    $server = app(PusherServer::class);
    $verifyOrigin = new ReflectionMethod($server, 'verifyOrigin');

    try {
        $verifyOrigin->invoke($server, $connection);
    } catch (InvalidOrigin) {
        return false;
    }

    return true;
}

test('reverb accepts local network origins and rejects foreign ones when APP_URL names a real host', function () {
    $allowedOrigins = reverbAllowedOriginsWith([
        'APP_URL' => 'https://fd.example.org',
        'REVERB_HOST' => null,
        'TRUSTED_HOSTS' => null,
        'REVERB_ALLOWED_ORIGINS' => null,
    ]);

    app()->instance(ChannelManager::class, Mockery::mock(ChannelManager::class));

    expect(app(PusherServer::class))->toBeInstanceOf(LocalNetworkPusherServer::class);

    foreach ([
        'https://fd.example.org',
        'https://ws.fd.example.org',
        'http://192.168.1.50',
        'http://192.168.1.50:8080',
        'http://10.0.0.5',
        'http://172.31.4.2',
        'http://100.101.102.103',
        'http://fdcommander.local',
        'http://localhost:8000',
        'http://[::1]:8000',
        'http://[fd7a:115c:a1e0::1]',
        'http://[fe80::1]',
    ] as $origin) {
        expect(reverbAcceptsOrigin($allowedOrigins, $origin))->toBeTrue("{$origin} should be accepted");
    }

    foreach ([
        'https://evil.example',
        'https://192.168.1.50.evil.example',
        'https://fd.example.org.evil.example',
        'http://172.32.0.1',
        'http://100.128.0.1',
        'http://8.8.8.8',
        'http://[2001:db8::1]',
        'http://evil.local.example',
        null,
    ] as $origin) {
        expect(reverbAcceptsOrigin($allowedOrigins, $origin))->toBeFalse(($origin ?? 'no origin').' should be rejected');
    }
});

test('reverb still accepts any origin while APP_URL is localhost', function () {
    expect(reverbAcceptsOrigin(['*'], 'https://evil.example'))->toBeTrue();
});

test('dompdf is confined to the public directory without pdf javascript', function () {
    $config = require config_path('dompdf.php');

    expect($config['options']['chroot'])->toBe(realpath(public_path()))
        ->and($config['options']['enable_javascript'])->toBeFalse()
        ->and($config['options']['enable_php'])->toBeFalse()
        ->and($config['options']['enable_remote'])->toBeFalse();
});

test('docker and helm pass octane settings through and require generated secrets', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));

    expect(file_get_contents(base_path('docker/supervisord.conf')))
        ->toContain('--workers=%(ENV_OCTANE_WORKERS)s --max-requests=%(ENV_OCTANE_MAX_REQUESTS)s')
        ->and(file_get_contents(base_path('docker/entrypoint.sh')))
        ->toContain('export OCTANE_WORKERS OCTANE_MAX_REQUESTS')
        ->and($compose)
        ->not->toContain('rootsecret')
        ->toContain('${DB_ROOT_PASSWORD:?')
        ->toContain('--requirepass "${REDIS_PASSWORD:?')
        ->and(file_get_contents(base_path('docker/setup.sh')))
        ->toContain('set_env "REDIS_PASSWORD"')
        ->toContain('chmod 600 "$ENV_FILE"');
});

test('helm chart bundles official mysql and redis images matching docker compose instead of bitnami', function () {
    $chartPath = base_path('charts/fd-commander');
    $compose = file_get_contents(base_path('docker-compose.yml'));
    $values = file_get_contents("{$chartPath}/values.yaml");
    $chart = file_get_contents("{$chartPath}/Chart.yaml");

    preg_match('/image: mysql:(\S+)/', $compose, $mysqlVersion);
    preg_match('/image: redis:(\S+)/', $compose, $redisVersion);

    expect($chart)->not->toContain('dependencies:')
        ->and(file_exists("{$chartPath}/Chart.lock"))->toBeFalse()
        ->and(glob("{$chartPath}/charts/*.tgz"))->toBe([])
        ->and($values)
        ->not->toContain('bitnamilegacy')
        ->not->toContain('allowInsecureImages')
        ->toMatch("/repository: mysql\n\s+tag: \"{$mysqlVersion[1]}\"/")
        ->toMatch("/repository: redis\n\s+tag: \"{$redisVersion[1]}\"/")
        ->toContain('runAsUser: 999');

    foreach (['appendfsync always', 'no-appendfsync-on-rewrite no', 'aof-use-rdb-preamble yes', 'maxmemory-policy volatile-lru', 'tcp-keepalive 60'] as $redisSetting) {
        expect($compose)->toContain("--{$redisSetting}")
            ->and($values)->toContain($redisSetting);
    }

    expect(file_get_contents("{$chartPath}/templates/redis.yaml"))
        ->toContain('- --requirepass')
        ->toContain('REDISCLI_AUTH')
        ->and(file_get_contents("{$chartPath}/templates/mysql.yaml"))
        ->toContain('key: mysql-root-password')
        ->toContain('{{ include "fd-commander.databaseSecretName" . }}')
        ->toContain('claimName: {{ tpl $persistence.existingClaim . }}')
        ->toContain('chown -h');
});

test('helm falls back to the official images when values carried over from chart 0.x still name bitnami', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));
    $mysql = file_get_contents(base_path('charts/fd-commander/templates/mysql.yaml'));
    $redis = file_get_contents(base_path('charts/fd-commander/templates/redis.yaml'));

    preg_match('/image: mysql:(\S+)/', $compose, $mysqlVersion);
    preg_match('/image: redis:(\S+)/', $compose, $redisVersion);

    foreach ([$mysql, $redis] as $template) {
        expect($template)
            ->toContain('{{- if contains "bitnami" $imageRepository }}')
            ->toContain('{{- if hasKey $podSecurityContext "enabled" }}')
            ->toContain('{{- if hasKey $containerSecurityContext "enabled" }}');
    }

    expect($mysql)
        ->toContain("{{- \$imageTag = \"{$mysqlVersion[1]}\" }}")
        ->toContain('dig "runAsUser" 999 $podSecurityContext')
        ->not->toContain('.Values.mysql.primary.podSecurityContext.runAsUser')
        ->and($redis)
        ->toContain("{{- \$imageTag = \"{$redisVersion[1]}\" }}")
        ->toContain('checksum/config: {{ $redisConf | sha256sum }}')
        ->toContain('{{- $redisConf | nindent 4 }}');
});

test('helm upgrade docs avoid reuse-values and explain the forbidden statefulset error', function () {
    $readme = file_get_contents(base_path('charts/fd-commander/README.md'));

    expect($readme)
        ->toContain('helm upgrade <release> charts/fd-commander -n <namespace> --reset-then-reuse-values')
        ->not->toMatch('/helm upgrade[^\n]*--reuse-values/')
        ->toContain('spec: Forbidden')
        ->and(file_get_contents(base_path('charts/fd-commander/templates/NOTES.txt')))
        ->toContain('--reset-then-reuse-values');
});

test('trusted hosts, hsts and reverb origins are wired through helm, deploy.sh and update.sh', function () {
    $chartPath = base_path('charts/fd-commander');

    expect(file_get_contents("{$chartPath}/templates/configmap.yaml"))
        ->toContain('{{- with dig "trustedHosts" "" .Values.app }}')
        ->toContain('TRUSTED_HOSTS: {{ join "," $trustedHosts | quote }}')
        ->toContain('SECURITY_HSTS_MAX_AGE: {{ dig "hstsMaxAge" 0 .Values.app | quote }}')
        ->toContain('REVERB_ALLOWED_ORIGINS:')
        ->and(file_get_contents("{$chartPath}/values.yaml"))
        ->toContain('trustedHosts: ""')
        ->toContain('hstsMaxAge: 0')
        ->toContain('allowedOrigins: ""')
        ->and(file_get_contents("{$chartPath}/questions.yaml"))
        ->toContain('variable: app.trustedHosts')
        ->toContain('variable: app.hstsMaxAge')
        ->toContain('variable: reverb.allowedOrigins');

    $deploy = file_get_contents(base_path('deploy.sh'));
    $update = file_get_contents(base_path('update.sh'));

    expect($deploy)
        ->toContain('--trusted-hosts) TRUSTED_HOSTS="$2"')
        ->toContain('set_env "TRUSTED_HOSTS" "$TRUSTED_HOSTS"')
        ->and($update)->toContain('set_env "TRUSTED_HOSTS" ""');

    // HSTS comes from the app, and deploy.sh only turns it on for automatic
    // Let's Encrypt certificates, never for custom (possibly self-signed) ones.
    foreach ([$deploy, $update] as $script) {
        expect($script)->not->toContain("echo 'header Strict-Transport-Security");
    }

    $acmeBranch = substr($deploy, strpos($deploy, 'Caddy will automatically obtain'));
    $customBranch = substr($deploy, strpos($deploy, 'Using custom certificate'), strpos($deploy, 'Caddy will automatically obtain') - strpos($deploy, 'Using custom certificate'));

    expect($acmeBranch)->toContain('set_env "SECURITY_HSTS_MAX_AGE" "31536000"')
        ->and($customBranch)->not->toContain('SECURITY_HSTS_MAX_AGE"');
});

test('update.sh keeps the site up until migrations and checks old tls settings before using them', function () {
    $update = file_get_contents(base_path('update.sh'));
    $main = substr($update, strpos($update, "\nmain() {"));

    $order = array_map(fn (string $step) => strpos($main, "\n    {$step}\n"), [
        'trap on_exit EXIT', 'repair_env_access', 'fetch_updates', 'pull_updates', 'reexec_if_script_changed',
        'install_dependencies', 'stage_frankenphp_update', 'enter_maintenance', 'take_pre_update_backup',
        'run_migrations', 'restart_services', 'leave_maintenance',
    ]);

    expect($order)->not->toContain(false)
        ->and($order)->toBe(collect($order)->sort()->values()->all())
        ->and($update)
        ->toContain('trap on_exit EXIT')
        ->toContain('[[ -z "${FDC_UPDATE_REEXEC:-}" ]] || return 0')
        ->toContain('export FDC_UPDATE_REEXEC=1')
        ->toContain('sudo -u fdcommander test -r "$file"')
        ->toContain('openssl x509 -checkend 0 -noout -in "$cert"')
        ->toContain('! tls_files_usable "$cert" "$key"');
});

test('deploy scripts never make .env world-readable while fixing permissions', function () {
    foreach (['deploy.sh', 'update.sh'] as $script) {
        $contents = file_get_contents(base_path($script));
        $function = substr($contents, strpos($contents, 'apply_app_permissions() {'));
        $function = substr($function, 0, strpos($function, "\n}\n"));

        expect($function)
            ->not->toContain('chmod -R u+rwX,go+rX,go-w "$APP_PATH"')
            ->toContain('-path "$APP_PATH/.env" -o')
            ->and(strpos($function, 'chmod 640 "$APP_PATH/.env"'))
            ->toBeLessThan(strpos($function, 'go+rX'));
    }
});

test('docker setup warns when a new env file meets an existing mysql volume', function () {
    expect(file_get_contents(base_path('docker/setup.sh')))
        ->toContain('if $CREATED && mysql_volume_exists; then')
        ->toContain('docker volume inspect "${project}_mysql-data"');
});

/**
 * Returns the body of a shell function from a script, up to its closing brace.
 */
function shellFunction(string $script, string $name): string
{
    $contents = file_get_contents(base_path($script));
    $function = substr($contents, strpos($contents, "{$name}() {"));

    return substr($function, 0, strpos($function, "\n}\n") + 2);
}

test('every native script takes the web group from the app user, not the root-owned install directory', function () {
    foreach (['deploy.sh', 'update.sh', 'restore.sh', 'backup.sh'] as $script) {
        expect(file_get_contents(base_path($script)))->not->toContain("stat -c '%G' \"\$APP_PATH\"");
    }

    expect(file_get_contents(base_path('update.sh')))->toContain('WEB_GROUP=$(id -gn fdcommander 2>/dev/null)')
        ->and(file_get_contents(base_path('restore.sh')))->toContain('WEB_GROUP=$(id -gn fdcommander 2>/dev/null)')
        ->and(shellFunction('deploy.sh', 'use_app_user_group'))->toContain('WEB_GROUP=$(id -gn fdcommander)');

    // update.sh repairs an .env an earlier run left unreadable, then runs the
    // full update so caches built without it are rebuilt.
    expect(shellFunction('update.sh', 'repair_env_access'))
        ->toContain('chown "root:${WEB_GROUP}" "$env_file"')
        ->toContain('chmod 640 "$env_file"')
        ->toContain('FORCE=1');

    expect(shellFunction('restore.sh', 'restore_native'))
        ->toContain('chown "root:${WEB_GROUP}" "$CURRENT_ENV"');
});

test('native scripts set a umask and never mark storage files executable', function () {
    foreach (['deploy.sh', 'update.sh', 'restore.sh'] as $script) {
        expect(file_get_contents(base_path($script)))->toContain("\numask 022\n");
    }

    foreach (['deploy.sh', 'update.sh'] as $script) {
        expect(shellFunction($script, 'apply_app_permissions'))
            ->not->toContain('chmod -R ug+rwX')
            ->toContain('-type f -exec chmod ug+rw,a-x,o-w {} +')
            ->toContain('-type d -exec chmod ug+rwx,o-w {} +');
    }
});

test('certificates under /tmp or /var/tmp are refused because the services run with PrivateTmp', function () {
    foreach (['deploy.sh', 'update.sh'] as $script) {
        $function = shellFunction($script, 'path_in_private_tmp');

        foreach (['/tmp/certs/site.crt' => 0, '/var/tmp/site.key' => 0, '/tmp/../tmp/a.pem' => 0, '/etc/ssl/site.crt' => 1, '/home/admin/tmp/site.crt' => 1, '/tmpfoo/site.crt' => 1] as $path => $expected) {
            exec('bash -c '.escapeshellarg("{$function}\npath_in_private_tmp ".escapeshellarg($path)), $output, $status);

            expect($status)->toBe($expected, "{$script}: {$path}");
        }
    }

    expect(shellFunction('update.sh', 'tls_files_usable'))->toContain('if path_in_private_tmp "$file"; then')
        ->and(shellFunction('deploy.sh', 'configure_ssl'))->toContain('if path_in_private_tmp "$file"; then')
        ->and(shellFunction('deploy.sh', 'validate_args'))->toContain('path_in_private_tmp "$tls_file"');
});

test('update.sh downloads frankenphp before maintenance and checks the services before reporting success', function () {
    $update = file_get_contents(base_path('update.sh'));
    $main = substr($update, strpos($update, "\nmain() {"));

    expect(shellFunction('update.sh', 'restart_services'))
        ->toContain('install_staged_frankenphp')
        ->not->toContain('stage_frankenphp_update')
        ->and(shellFunction('update.sh', 'install_staged_frankenphp'))->not->toContain('curl')
        ->and(shellFunction('update.sh', 'stage_frankenphp_update'))->not->toContain('mv -f')
        ->and(shellFunction('update.sh', 'on_exit'))->toContain('rm -f "$FRANKENPHP_STAGED"');

    // A failed health check exits non-zero before the success banner.
    expect(strpos($main, 'if ! verify_services; then'))->toBeGreaterThan(strpos($main, "\n    leave_maintenance\n"))
        ->and(strpos($main, 'exit 1'))->toBeLessThan(strpos($main, 'Update Complete'))
        ->and(shellFunction('update.sh', 'check_units_stay_up'))
        ->toContain('now=$(unit_restarts "${APP_UNITS[i]}")')
        ->and(shellFunction('update.sh', 'unit_restarts'))->toContain('NRestarts')
        ->and($update)->toContain('journalctl -u ${APP_UNITS[i]}.service')
        ->and(shellFunction('update.sh', 'check_app_answers'))
        ->toContain('local -a resolve=(--resolve "${host}:${port}:127.0.0.1")')
        ->toContain('${resolve[@]+"${resolve[@]}"} "$url"')
        ->toContain('/up');
});

test('update.sh only re-executes a pulled update.sh that supports re-execution', function () {
    expect(shellFunction('update.sh', 'reexec_if_script_changed'))
        ->toContain("grep -q 'FDC_UPDATE_REEXEC' \"\$new_script\"");
});

test('deploy.sh starts redis before the migrations, which clear the redis cache', function () {
    $deploy = file_get_contents(base_path('deploy.sh'));
    $main = substr($deploy, strpos($deploy, "\nmain() {"));

    expect(strpos($main, "\n    configure_redis\n"))->toBeLessThan(strpos($main, "\n    setup_database\n"));
});

test('helm keeps generated secrets on uninstall and rolls pods when their passwords change', function () {
    $chartPath = base_path('charts/fd-commander');
    $helpers = file_get_contents("{$chartPath}/templates/_helpers.tpl");
    $mysql = file_get_contents("{$chartPath}/templates/mysql.yaml");
    $redis = file_get_contents("{$chartPath}/templates/redis.yaml");
    $secret = file_get_contents("{$chartPath}/templates/secret.yaml");

    foreach ([$mysql, $redis, $secret] as $template) {
        expect($template)
            ->toContain('helm.sh/resource-policy: keep')
            ->toContain('{{- include "fd-commander.credentials" . }}');
    }

    expect($helpers)
        ->toContain('{{- $_ := set .Values "_fdcCredentials" $credentials }}')
        ->toContain('(get $appData "APP_KEY" | b64dec)')
        ->toContain('(get $mysqlData "mysql-password" | b64dec)')
        ->toContain('(get $redisData "redis-password" | b64dec)')
        ->and(file_get_contents("{$chartPath}/templates/deployment.yaml"))
        ->toContain('checksum/secret: {{ include "fd-commander.appCredentialsChecksum" . }}')
        ->and($redis)->toContain('checksum/secret: {{ . }}')
        ->and($mysql)->not->toContain('checksum/secret')
        ->and(file_get_contents("{$chartPath}/README.md"))
        ->toContain('### Uninstalling')
        ->toContain('ALTER USER');
});

test('helm bundled mysql and redis run read-only with safe defaults for values carried over from chart 0.x', function () {
    $chartPath = base_path('charts/fd-commander');
    $mysql = file_get_contents("{$chartPath}/templates/mysql.yaml");
    $redis = file_get_contents("{$chartPath}/templates/redis.yaml");

    foreach ([$mysql, $redis] as $template) {
        expect($template)
            ->toContain('"readOnlyRootFilesystem" true')
            ->toContain('{{- toYaml $resources | nindent 12 }}')
            ->toContain('"ephemeral-storage"')
            ->toContain('mountPath: /tmp');
    }

    expect($mysql)->toContain('mountPath: /var/run/mysqld')
        ->and($redis)->toContain('{{- $redisConf = append $redisConf "maxmemory 256mb" }}')
        ->and(substr_count(file_get_contents("{$chartPath}/values.yaml"), 'readOnlyRootFilesystem: true'))->toBe(2)
        ->and(substr_count(file_get_contents("{$chartPath}/values.yaml"), 'ephemeral-storage:'))->toBeGreaterThanOrEqual(6);
});

test('helm external logger node ports may be left blank and secure cookies follow the reverb scheme', function () {
    $chartPath = base_path('charts/fd-commander');

    expect(file_get_contents("{$chartPath}/templates/service-udp.yaml"))
        ->toContain('{{- with dig "nodePorts" "n1mm" "" .Values.externalLoggers.service }}')
        ->not->toContain('nodePort: {{ .Values.externalLoggers.service.nodePorts')
        ->and(file_get_contents("{$chartPath}/templates/configmap.yaml"))
        ->toContain('include "fd-commander.publicHttps" .')
        ->and(file_get_contents("{$chartPath}/templates/_helpers.tpl"))
        ->toContain('{{- else if include "fd-commander.publicHttps" . }}')
        ->toContain('(and .Values.ingress.enabled .Values.ingress.tls.enabled)');
});

test('helm sets trusted proxies for the ingress because the app trusts only loopback by default', function () {
    $chartPath = base_path('charts/fd-commander');
    $ranges = '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7';

    expect(file_get_contents("{$chartPath}/templates/configmap.yaml"))
        ->toContain("TRUSTED_PROXIES: {{ dig \"trustedProxies\" \"{$ranges}\" .Values.app | quote }}")
        ->and(file_get_contents("{$chartPath}/values.yaml"))->toContain("trustedProxies: \"{$ranges}\"")
        ->and(file_get_contents("{$chartPath}/questions.yaml"))->toContain('variable: app.trustedProxies')
        ->and(file_get_contents("{$chartPath}/README.md"))->toContain('`app.trustedProxies`');
});

test('deploy.sh writes TRUSTED_PROXIES only when given and update.sh keeps an existing value', function () {
    $deploy = file_get_contents(base_path('deploy.sh'));

    expect($deploy)
        ->toContain('--trusted-proxies) TRUSTED_PROXIES="$2"')
        ->toContain("if [[ -n \"\$TRUSTED_PROXIES\" ]]; then\n        set_env \"TRUSTED_PROXIES\" \"\$TRUSTED_PROXIES\"");

    preg_match('/\$proxy" =~ (.+) \]\]; then/', $deploy, $match);

    foreach (['192.168.1.10' => 0, '10.0.0.0/8' => 0, '::1' => 0, 'fd00::/8' => 0, '*' => 0, '10.0.0.5;rm' => 1, 'proxy.lan' => 1, '10.0.0.1 x' => 1] as $proxy => $expected) {
        exec('bash -c '.escapeshellarg('proxy=$1; [[ "$proxy" =~ '.$match[1].' ]]').' _ '.escapeshellarg($proxy), $output, $status);

        expect($status)->toBe($expected, $proxy);
    }

    expect(shellFunction('update.sh', 'note_trusted_proxies'))
        ->toContain("grep -qE '^#? *TRUSTED_PROXIES=' \"\$env_file\" && return 0")
        ->not->toContain('set_env "TRUSTED_PROXIES"')
        ->and(shellFunction('update.sh', 'migrate_env_settings'))->toContain('note_trusted_proxies');
});

test('every deploy path polls an idle queue every second so broadcasts go out promptly', function () {
    $supervisord = file_get_contents(base_path('docker/supervisord.conf'));
    $deploy = file_get_contents(base_path('deploy.sh'));
    $update = file_get_contents(base_path('update.sh'));

    expect($supervisord)->toContain('queue:work --sleep=1 ')->not->toContain('--sleep=3')
        ->and($deploy)->toContain('queue:work --sleep=1 ')->not->toContain('--sleep=3 ')
        ->and($update)->toContain('migrate_queue_unit')
        ->toContain("sed -i -E 's|queue:work --sleep=3 |queue:work --sleep=1 |'");
});
