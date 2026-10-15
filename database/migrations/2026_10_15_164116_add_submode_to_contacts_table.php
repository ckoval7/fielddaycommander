<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('contacts', 'submode')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('submode', 20)->nullable()->after('mode_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contacts', 'submode')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('submode');
        });
    }
};
