<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Support\ExternalLoggers;

/**
 * What happened during one WireHarness run.
 */
final readonly class WireRun
{
    /**
     * @param  list<list<string>>  $reads  Per TCP connection, each chunk a read() returned
     * @param  int  $drops  Datagrams the kernel dropped because the listener fell behind
     */
    public function __construct(
        public int $exitCode,
        public string $output,
        public array $reads,
        public int $drops,
        public int $port,
    ) {}

    /**
     * The messages connection $index received, decoded correctly.
     *
     * @return list<string>
     */
    public function messages(int $index = 0): array
    {
        return N3fjp::messages(implode('', $this->reads[$index] ?? []));
    }

    /**
     * The messages connection $index received, decoded read by read the
     * way an N3FJP client does.
     *
     * @return list<string>
     */
    public function messagesAsClientSees(int $index = 0): array
    {
        return N3fjp::decodeLikeClient($this->reads[$index] ?? []);
    }
}
