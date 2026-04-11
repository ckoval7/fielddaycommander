<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('external_logger_settings', function (Blueprint $table) {
            $table->unsignedInteger('pid')->nullable()->after('port');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_logger_settings', function (Blueprint $table) {
            $table->dropColumn('pid');
        });
    }
};
