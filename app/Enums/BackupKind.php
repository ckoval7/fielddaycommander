<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

/**
 * Why a whole-site backup was made, which decides what it holds and whether
 * it is cleaned up automatically.
 */
enum BackupKind: string
{
    /** Someone asked for it. Never deleted automatically. */
    case Manual = 'manual';

    /** Taken on a schedule. Only the newest few are kept. */
    case Automatic = 'automatic';

    /** Taken just before a restore replaces everything, so it can be undone. */
    case BeforeRestore = 'before-restore';

    /**
     * Whether photos and other uploaded files go in the backup. Automatic
     * backups leave them out so dozens of them fit on a small disk.
     */
    public function includesFiles(): bool
    {
        return $this !== self::Automatic;
    }

    /**
     * The words after "Field Day Commander" in the backup's file name.
     */
    public function fileLabel(): string
    {
        return match ($this) {
            self::Manual => 'backup',
            self::Automatic => 'automatic backup',
            self::BeforeRestore => 'backup before restore',
        };
    }
}
