<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

/**
 * Listener processes on Linux and macOS: backgrounded by the shell,
 * stopped with SIGTERM, identified through /proc.
 */
class PosixListenerProcessRunner extends ListenerProcessRunner
{
    /**
     * Uses exec() with & instead of Symfony Process because its __destruct()
     * kills the child on GC.
     */
    public function spawnDetached(array $arguments): ?int
    {
        $command = implode(' ', array_map(escapeshellarg(...), $arguments)).' > /dev/null 2>&1 & echo $!';

        $output = [];
        exec($command, $output);
        $pid = (int) ($output[0] ?? 0);

        return $pid > 0 ? $pid : null;
    }

    public function isAlive(int $pid): bool
    {
        return posix_kill($pid, 0);
    }

    /**
     * SIGTERM makes a listener drain its queued packets and exit.
     */
    public function terminate(int $pid): void
    {
        posix_kill($pid, SIGTERM);
    }

    public function commandLine(int $pid): ?string
    {
        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");

        return $cmdline === false ? null : $cmdline;
    }
}
