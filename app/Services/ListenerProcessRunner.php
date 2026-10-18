<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

/**
 * Thin wrapper around OS process primitives used to manage detached
 * external logger listener processes. Exists so the process layer can
 * be swapped out in tests without spawning real processes.
 */
class ListenerProcessRunner
{
    /**
     * Run a shell command that backgrounds a process and echoes its PID.
     *
     * Uses exec() with & instead of Symfony Process because its __destruct()
     * kills the child on GC.
     *
     * @return int|null The PID of the detached process, or null if none was reported
     */
    public function spawnDetached(string $command): ?int
    {
        $output = [];
        exec($command, $output);
        $pid = (int) ($output[0] ?? 0);

        return $pid > 0 ? $pid : null;
    }

    public function isAlive(int $pid): bool
    {
        return posix_kill($pid, 0);
    }

    public function terminate(int $pid): void
    {
        posix_kill($pid, SIGTERM);
    }

    /**
     * Read the raw command line of a process, or null if it does not exist.
     */
    public function commandLine(int $pid): ?string
    {
        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");

        return $cmdline === false ? null : $cmdline;
    }
}
