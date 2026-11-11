<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

/**
 * Thin wrapper around OS process primitives used to manage detached
 * external logger listener processes. Exists so the process layer can
 * be swapped out in tests without spawning real processes, and so each
 * operating system gets its own implementation.
 */
abstract class ListenerProcessRunner
{
    /**
     * The runner for the operating system PHP is running on.
     */
    public static function forOperatingSystem(string $family = PHP_OS_FAMILY): self
    {
        return $family === 'Windows'
            ? new WindowsListenerProcessRunner
            : new PosixListenerProcessRunner;
    }

    /**
     * Start a process that keeps running after this request ends.
     *
     * @param  list<string>  $arguments  The program followed by its arguments
     * @return int|null The PID of the detached process, or null if it could not be started
     */
    abstract public function spawnDetached(array $arguments): ?int;

    abstract public function isAlive(int $pid): bool;

    /**
     * Stop a process. Listeners asked to stop through ExternalLoggerManager
     * get the chance to finish their queued contacts first.
     */
    abstract public function terminate(int $pid): void;

    /**
     * Read the raw command line of a process, or null if it does not exist.
     */
    abstract public function commandLine(int $pid): ?string;
}
