<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Contracts;

interface ExternalLoggerListener
{
    public function start(): void;

    public function stop(): void;

    public function isRunning(): bool;

    public function getType(): string;
}
