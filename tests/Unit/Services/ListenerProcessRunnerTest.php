<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Services\ListenerProcessRunner;
use App\Services\PosixListenerProcessRunner;
use App\Services\WindowsListenerProcessRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

test('each operating system gets its own runner', function () {
    expect(ListenerProcessRunner::forOperatingSystem('Windows'))->toBeInstanceOf(WindowsListenerProcessRunner::class)
        ->and(ListenerProcessRunner::forOperatingSystem('Linux'))->toBeInstanceOf(PosixListenerProcessRunner::class)
        ->and(ListenerProcessRunner::forOperatingSystem('Darwin'))->toBeInstanceOf(PosixListenerProcessRunner::class)
        ->and(app(ListenerProcessRunner::class))->toBeInstanceOf(PosixListenerProcessRunner::class);
});

describe('POSIX', function () {
    test('spawns a detached process that survives, then terminates it', function () {
        $runner = new PosixListenerProcessRunner;

        $pid = $runner->spawnDetached(['sleep', '30']);

        expect($pid)->toBeInt()
            ->and($runner->isAlive($pid))->toBeTrue()
            ->and($runner->commandLine($pid))->toBe("sleep\x0030\x00");

        $runner->terminate($pid);
        usleep(200_000);

        // When the test runner is PID 1 (a bare container) nothing reaps the
        // child, so it can linger as a zombie: exited, but still listed.
        $stat = @file_get_contents("/proc/{$pid}/stat");
        $exited = $stat === false || preg_match('/\) Z /', $stat) === 1;

        expect($exited)->toBeTrue();
    });

    test('quotes each argument so paths with spaces reach the program intact', function () {
        $runner = new PosixListenerProcessRunner;
        $marker = storage_path('framework/testing/runner '.uniqid().'.txt');

        $pid = $runner->spawnDetached(['sh', '-c', 'printf %s "$1" > "$2"', 'sh', "it's here", $marker]);

        for ($attempt = 0; $attempt < 20 && ! is_file($marker); $attempt++) {
            usleep(50_000);
        }

        expect($pid)->toBeInt()
            ->and(file_get_contents($marker))->toBe("it's here");

        @unlink($marker);
    });
});

describe('Windows', function () {
    beforeEach(function () {
        Sleep::fake();
    });

    test('a process is alive when tasklist lists its PID', function () {
        Process::fake([
            '*tasklist*' => Process::result('"php.exe","4242","Services","0","31,212 K"'),
        ]);

        expect((new WindowsListenerProcessRunner)->isAlive(4242))->toBeTrue();

        Process::assertRan(fn (PendingProcess $process) => $process->command === ['tasklist', '/FI', 'PID eq 4242', '/FO', 'CSV', '/NH']);
    });

    test('a process is gone when tasklist finds no match', function () {
        Process::fake([
            '*tasklist*' => Process::result('INFO: No tasks are running which match the specified criteria.'),
        ]);

        expect((new WindowsListenerProcessRunner)->isAlive(4242))->toBeFalse();
    });

    test('terminate lets a listener that exits on its own go without killing it', function () {
        Process::fake([
            '*tasklist*' => Process::sequence()
                ->push(Process::result('"php.exe","4242","Services","0","31,212 K"'))
                ->push(Process::result('INFO: No tasks are running which match the specified criteria.'))
                ->dontFailWhenEmpty(),
        ]);

        (new WindowsListenerProcessRunner)->terminate(4242);

        Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'taskkill');
    });

    test('terminate force-kills a listener still running after the grace period', function () {
        Process::fake([
            '*tasklist*' => Process::result('"php.exe","4242","Services","0","31,212 K"'),
            '*taskkill*' => Process::result(),
        ]);

        (new WindowsListenerProcessRunner)->terminate(4242);

        Process::assertRan(fn (PendingProcess $process) => $process->command === ['taskkill', '/PID', '4242', '/T', '/F']);
        Sleep::assertSleptTimes(WindowsListenerProcessRunner::STOP_GRACE_MILLISECONDS / 250);
    });

    test('reads a command line from Win32_Process', function () {
        Process::fake([
            '*powershell*' => Process::result("C:\\FDC\\php\\php.exe C:\\FDC\\app\\artisan external-logger:n1mm --event=3\r\n"),
        ]);

        expect((new WindowsListenerProcessRunner)->commandLine(4242))
            ->toBe('C:\\FDC\\php\\php.exe C:\\FDC\\app\\artisan external-logger:n1mm --event=3');

        Process::assertRan(fn (PendingProcess $process) => str_contains($process->command[4], 'ProcessId=4242'));
    });

    test('a command line read moments ago is reused instead of starting PowerShell again', function () {
        Process::fake(['*powershell*' => Process::result("php.exe artisan external-logger:n1mm\r\n")]);
        $runner = new WindowsListenerProcessRunner;

        $runner->commandLine(4242);
        $runner->commandLine(4242);
        $runner->commandLine(4243);

        Process::assertRanTimes(fn (PendingProcess $process) => str_contains($process->command[4], 'ProcessId=4242'), 1);
        Process::assertRanTimes(fn (PendingProcess $process) => str_contains($process->command[4], 'ProcessId=4243'), 1);
    });

    test('a process with no command line is reported as gone', function () {
        Process::fake(['*powershell*' => Process::result('')]);

        expect((new WindowsListenerProcessRunner)->commandLine(4242))->toBeNull();
    });
});
