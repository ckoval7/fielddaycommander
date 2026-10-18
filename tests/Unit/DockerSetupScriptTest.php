<?php

use Illuminate\Support\Facades\Process;

test('the entrypoint refuses the placeholder redis password before doing anything', function () {
    $result = Process::env([
        'DB_PASSWORD' => 'secret',
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'REDIS_PASSWORD' => 'null',
        'PATH' => getenv('PATH'),
    ])->run(['bash', base_path('docker/entrypoint.sh')]);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain("REDIS_PASSWORD is still the placeholder 'null'");
});

test('setup.sh says it kept the existing root password instead of generating one', function () {
    $directory = sys_get_temp_dir().'/fdc-setup-'.uniqid();
    mkdir($directory);
    copy(base_path('.env.example'), $directory.'/.env.example');
    file_put_contents($directory.'/.env', "APP_KEY=base64:abc\nDB_PASSWORD=existing\nREDIS_PASSWORD=null\n");

    $result = Process::path($directory)
        ->env(['PATH' => '/usr/bin:/bin'])
        ->run(['bash', base_path('docker/setup.sh')]);

    $env = file_get_contents($directory.'/.env');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->not->toContain('Generated DB_ROOT_PASSWORD')
        ->and($result->output())->toContain("Set DB_ROOT_PASSWORD to the existing database's password")
        ->and($env)->toContain('DB_ROOT_PASSWORD=rootsecret')
        ->and($env)->toContain('DB_PASSWORD=existing')
        ->and($env)->toMatch('/^REDIS_PASSWORD=[A-Za-z0-9]{32}$/m');

    exec('rm -rf '.escapeshellarg($directory));
});
