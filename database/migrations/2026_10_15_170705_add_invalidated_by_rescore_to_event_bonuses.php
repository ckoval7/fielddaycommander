<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('event_bonuses', 'invalidated_by_rescore')) {
            return;
        }

        Schema::table('event_bonuses', function (Blueprint $table) {
            $table->boolean('invalidated_by_rescore')->default(false)->after('is_verified');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('event_bonuses', 'invalidated_by_rescore')) {
            return;
        }

        Schema::table('event_bonuses', function (Blueprint $table) {
            $table->dropColumn('invalidated_by_rescore');
        });
    }
};
