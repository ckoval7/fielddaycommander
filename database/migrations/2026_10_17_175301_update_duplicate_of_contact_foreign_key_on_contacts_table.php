<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Null out duplicate_of_contact_id when the original contact is removed,
     * so deleting an event (which cascades to its contacts) cannot fail on
     * the self-referencing key.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of_contact_id']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreign('duplicate_of_contact_id')->references('id')->on('contacts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of_contact_id']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreign('duplicate_of_contact_id')->references('id')->on('contacts');
        });
    }
};
