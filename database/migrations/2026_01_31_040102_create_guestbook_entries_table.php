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
        Schema::create('guestbook_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->constrained('event_configurations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->string('callsign', 20)->nullable();
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->string('email', 100)->nullable();
            $table->text('comments')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->enum('presence_type', ['in_person', 'online'])->default('online');
            $table->enum('visitor_category', ['elected_official', 'arrl_official', 'agency', 'media', 'ares_races', 'ham_club', 'youth', 'general_public'])->default('general_public');
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('event_configuration_id');
            $table->index('user_id');
            $table->index('presence_type');
            $table->index('visitor_category');
            $table->index('is_verified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guestbook_entries');
    }
};
