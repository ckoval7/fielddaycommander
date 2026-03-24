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
        Schema::create('w1aw_bulletins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->unique()->constrained('event_configurations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('frequency');
            $table->string('mode');
            $table->text('bulletin_text');
            $table->dateTime('received_at');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('w1aw_bulletins');
    }
};
