<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('logger_user_id')->nullable()->change();
            $table->foreignId('band_id')->nullable()->change();
            $table->foreignId('mode_id')->nullable()->change();
        });

        Schema::table('operating_sessions', function (Blueprint $table) {
            $table->foreignId('operator_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('logger_user_id')->nullable(false)->change();
            $table->foreignId('band_id')->nullable(false)->change();
            $table->foreignId('mode_id')->nullable(false)->change();
        });

        Schema::table('operating_sessions', function (Blueprint $table) {
            $table->foreignId('operator_user_id')->nullable(false)->change();
        });
    }
};
