<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember which account in the sandbox belongs to the visitor who
     * provisioned it, so a guest starting over is not counted as the
     * sandbox being reset. It points into the visitor's own demo database,
     * so it carries no foreign key. Existing rows stay null.
     */
    public function up(): void
    {
        Schema::table('demo_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('host_user_id')->nullable()->after('participant_count');
        });
    }

    public function down(): void
    {
        Schema::table('demo_sessions', function (Blueprint $table) {
            $table->dropColumn('host_user_id');
        });
    }
};
