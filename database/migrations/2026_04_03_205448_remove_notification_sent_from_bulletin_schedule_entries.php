<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulletin_schedule_entries', function (Blueprint $table) {
            $table->dropIndex(['notification_sent', 'scheduled_at']);
            $table->dropColumn('notification_sent');
        });
    }

    public function down(): void
    {
        Schema::table('bulletin_schedule_entries', function (Blueprint $table) {
            $table->boolean('notification_sent')->default(false)->after('source');
            $table->index(['notification_sent', 'scheduled_at']);
        });
    }
};
