<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make photo de-duplication per event, and let a deleted photo be uploaded
     * again as a new one. Duplicates among live photos are checked in GalleryUpload,
     * since MySQL has no partial unique index that could skip soft-deleted rows.
     */
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropUnique(['file_hash']);
            $table->index(['event_configuration_id', 'file_hash']);
        });
    }

    /**
     * Restore the global unique hash index. Once the same photo exists in two
     * events (or was re-uploaded after a delete) the old unique index can't be
     * rebuilt, so a plain index is used instead and the rollback still completes.
     */
    public function down(): void
    {
        $hasDuplicateHashes = DB::table('images')
            ->select('file_hash')
            ->groupBy('file_hash')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        Schema::table('images', function (Blueprint $table) use ($hasDuplicateHashes) {
            $table->dropIndex(['event_configuration_id', 'file_hash']);

            if ($hasDuplicateHashes) {
                // Same name as the unique index, so up() can drop it either way
                $table->index('file_hash', 'images_file_hash_unique');
            } else {
                $table->unique('file_hash');
            }
        });
    }
};
