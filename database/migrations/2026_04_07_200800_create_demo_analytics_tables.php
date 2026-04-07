<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('session_uuid', 36)->unique();
            $table->string('role', 30);
            $table->string('visitor_hash', 64);
            $table->text('user_agent');
            $table->string('device_type', 20)->default('desktop');
            $table->text('referrer')->nullable();
            $table->char('ip_country', 2)->nullable();
            $table->timestamp('provisioned_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->unsignedInteger('total_page_views')->default(0);
            $table->unsignedInteger('total_actions')->default(0);
            $table->boolean('was_reset')->default(false);
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamps();

            $table->index('visitor_hash');
            $table->index('provisioned_at');
        });

        Schema::create('demo_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demo_session_id')->constrained('demo_sessions')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name', 100);
            $table->string('route_name', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['demo_session_id', 'type']);
            $table->index(['type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_events');
        Schema::dropIfExists('demo_sessions');
    }
};
