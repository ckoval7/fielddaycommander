<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->string('unavailable_reason', 40)->nullable()->after('max_power_watts');
            $table->text('unavailable_note')->nullable()->after('unavailable_reason');
            $table->timestamp('unavailable_since')->nullable()->after('unavailable_note');
            $table->foreignId('unavailable_by_user_id')->nullable()->after('unavailable_since')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unavailable_by_user_id');
            $table->dropColumn(['unavailable_reason', 'unavailable_note', 'unavailable_since']);
        });
    }
};
