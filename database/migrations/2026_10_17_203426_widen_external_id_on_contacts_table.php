<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External loggers can send QSO ids longer than the 32 characters N1MM uses,
 * which failed the insert and dropped the contact. The index is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('external_id', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('external_id', 32)->nullable()->change();
        });
    }
};
