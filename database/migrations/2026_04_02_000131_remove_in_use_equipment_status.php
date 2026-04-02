<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Convert any existing in_use records to delivered
        DB::table('equipment_event')
            ->where('status', 'in_use')
            ->update(['status' => 'delivered']);
    }

    public function down(): void
    {
        // No reversal needed — we can't know which records were previously in_use
    }
};
