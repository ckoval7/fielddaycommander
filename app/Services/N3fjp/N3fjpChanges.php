<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services\N3fjp;

/**
 * Log changes made outside the N3FJP clients, to send to them.
 */
class N3fjpChanges
{
    /** @var list<string> Records of newly numbered contacts, in number order */
    public array $adds = [];

    /** @var list<string> Records of changed contacts */
    public array $edits = [];

    /** @var list<int> Numbers of deleted contacts */
    public array $deletes = [];

    /** Whether clients should compare their logs afterwards (the highest number was deleted). */
    public bool $checkAfter = false;

    /** Whether to send every client a full reload instead of the edits. */
    public bool $reloadAll = false;

    public function isEmpty(): bool
    {
        return $this->adds === [] && $this->edits === [] && $this->deletes === [] && ! $this->reloadAll;
    }
}
