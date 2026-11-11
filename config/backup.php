<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Whole-site backups for SQLite installs (the Windows server). Linux, Docker
 * and Helm installs use MySQL and back up with backup.sh instead.
 */
return [

    /*
     * Where backups are written. The Windows installer points this at
     * Public Documents so people can find it in File Explorer.
     */
    'path' => env('BACKUP_PATH', storage_path('backups')),

    /*
     * Take automatic backups: hourly while an event is in progress, daily
     * otherwise. They hold the database and settings but not photos, so they
     * stay small.
     */
    'automatic' => (bool) env('BACKUP_AUTOMATIC', false),

    /*
     * How many automatic backups to keep. Backups people make themselves are
     * never deleted automatically.
     */
    'keep_automatic' => (int) env('BACKUP_KEEP_AUTOMATIC', 48),

];
