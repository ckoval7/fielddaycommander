<?php

namespace App\Contracts;

interface ExternalLoggerListener
{
    public function start(): void;

    public function stop(): void;

    public function isRunning(): bool;

    public function getType(): string;
}
