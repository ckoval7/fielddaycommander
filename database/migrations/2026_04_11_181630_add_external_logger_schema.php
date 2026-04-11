<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->string('hostname', 50)->nullable()->after('name');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('n1mm_id', 32)->nullable()->after('is_imported');
            $table->string('external_source', 20)->nullable()->after('n1mm_id');
            $table->index('n1mm_id');
            $table->index('external_source');
        });

        Schema::table('operating_sessions', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('power_watts');
            $table->string('external_source', 20)->nullable()->after('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('hostname');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['n1mm_id']);
            $table->dropIndex(['external_source']);
            $table->dropColumn(['n1mm_id', 'external_source']);
        });

        Schema::table('operating_sessions', function (Blueprint $table) {
            $table->dropColumn(['last_activity_at', 'external_source']);
        });
    }
};
