<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Equipment\Concerns;

/**
 * Bulk delete for the equipment list pages that have a selection bar
 * (My Equipment and Club Equipment).
 *
 * Builds on ListsEquipment and ManagesEquipmentCommitments: the selection
 * comes from $selectedIds, authorizeCommitAction() gates the action and
 * scopeSelectedEquipment() limits it to the items this page may manage.
 * Radios that are a station's primary radio in an event that hasn't ended
 * are kept, and the result is reported in a single toast. Each deleted item
 * gets an audit log entry, as a single delete does.
 */
trait BulkDeletesEquipment
{
    /**
     * Delete the selected items this page may manage.
     */
    public function bulkDeleteEquipment(): void
    {
        $this->authorizeCommitAction();

        [
            'deletable' => $deletable,
            'blockedMessage' => $blockedMessage,
        ] = $this->partitionPrimaryRadios($this->scopeSelectedEquipment($this->selectedIds));

        foreach ($deletable as $equipment) {
            $this->logEquipmentDeletion($equipment);

            $equipment->delete();
        }

        $this->notifyBulkDeleteResult($deletable->count(), $blockedMessage);

        $this->selectedIds = [];

        unset($this->equipment);
    }
}
