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
        Schema::table('messages', function (Blueprint $table) {
            $table->dateTime('sent_at')->nullable()->after('received_from');
            $table->foreignId('sent_by_user_id')->nullable()->after('sent_at')->constrained('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['sent_by_user_id']);
            $table->dropColumn(['sent_at', 'sent_by_user_id']);
        });
    }
};
