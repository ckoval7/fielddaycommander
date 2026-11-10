<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

/**
 * Listener processes on Windows. There are no signals, so a stop is
 * requested through ExternalLoggerManager::requestStop(), which the
 * listener notices within a second or two; terminate() waits for it to
 * exit and only kills it if it doesn't.
 */
class WindowsListenerProcessRunner extends ListenerProcessRunner
{
    /**
     * How long terminate() waits for a listener to exit on its own.
     */
    public const int STOP_GRACE_MILLISECONDS = 8000;

    private const int STOP_POLL_MILLISECONDS = 250;

    public function spawnDetached(array $arguments): ?int
    {
        return $this->openDetached($arguments);
    }

    public function isAlive(int $pid): bool
    {
        $result = Process::run(['tasklist', '/FI', "PID eq {$pid}", '/FO', 'CSV', '/NH']);

        return $result->successful() && str_contains($result->output(), "\"{$pid}\"");
    }

    public function terminate(int $pid): void
    {
        $waited = 0;
        $deadline = hrtime(true) + self::STOP_GRACE_MILLISECONDS * 1_000_000;

        // Each tasklist call takes a few hundred milliseconds, so the real
        // clock caps the wait as well as the sleeps.
        while ($waited < self::STOP_GRACE_MILLISECONDS && hrtime(true) < $deadline && $this->isAlive($pid)) {
            Sleep::for(self::STOP_POLL_MILLISECONDS)->milliseconds();
            $waited += self::STOP_POLL_MILLISECONDS;
        }

        if ($this->isAlive($pid)) {
            Process::run(['taskkill', '/PID', (string) $pid, '/T', '/F']);
        }
    }

    public function commandLine(int $pid): ?string
    {
        $result = Process::run([
            'powershell', '-NoProfile', '-NonInteractive', '-Command',
            "(Get-CimInstance Win32_Process -Filter 'ProcessId={$pid}').CommandLine",
        ]);

        $commandLine = trim($result->output());

        return $result->successful() && $commandLine !== '' ? $commandLine : null;
    }

    /**
     * Start the process straight from its argument list, with no shell and no
     * console of its own. The handle is dropped without proc_close(), which
     * would wait for the listener to exit; on Windows dropping it leaves the
     * process running.
     *
     * @param  list<string>  $arguments
     */
    protected function openDetached(array $arguments): ?int
    {
        $process = @proc_open(
            $arguments,
            [0 => ['null'], 1 => ['null'], 2 => ['null']],
            $pipes,
            base_path(),
            null,
            ['create_process_group' => true],
        );

        if (! is_resource($process)) {
            return null;
        }

        $pid = proc_get_status($process)['pid'];

        return $pid > 0 ? $pid : null;
    }
}
