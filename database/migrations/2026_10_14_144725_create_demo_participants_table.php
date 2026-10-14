<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everyone who signed into a demo sandbox, with the name and call sign
     * they typed in. Generated identities are left null, so only what a
     * visitor supplied is kept. Rows are pruned along with their session.
     */
    public function up(): void
    {
        Schema::create('demo_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demo_session_id')->constrained('demo_sessions')->cascadeOnDelete();
            $table->string('role', 30);
            $table->boolean('via_invite')->default(false);
            $table->string('first_name', 50)->nullable();
            $table->string('last_name', 50)->nullable();
            $table->string('call_sign', 12)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_participants');
    }
};
