<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a demo visitor invite others into their sandbox. The token is the
     * secret in the shared join link, and the participant count enforces the
     * per-sandbox user limit. Existing rows each hold just their original visitor.
     */
    public function up(): void
    {
        Schema::table('demo_sessions', function (Blueprint $table) {
            $table->string('invite_token', 64)->nullable()->unique()->after('event_type');
            $table->unsignedSmallInteger('participant_count')->default(1)->after('invite_token');
        });
    }

    public function down(): void
    {
        Schema::table('demo_sessions', function (Blueprint $table) {
            $table->dropUnique(['invite_token']);
            $table->dropColumn(['invite_token', 'participant_count']);
        });
    }
};
