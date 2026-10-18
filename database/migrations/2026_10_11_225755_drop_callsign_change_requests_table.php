<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the never-used callsign change request table. Admins change
     * call signs directly through user management.
     */
    public function up(): void
    {
        Schema::dropIfExists('callsign_change_requests');
    }

    /**
     * The table held no data, so there is nothing to restore.
     */
    public function down(): void
    {
        //
    }
};
