<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Make radiogram-specific columns nullable
            $table->string('precedence')->nullable()->default('routine')->change();
            $table->string('station_of_origin')->nullable()->change();
            $table->string('check')->nullable()->change();
            $table->string('place_of_origin')->nullable()->change();

            // Add ICS-213-specific columns
            $table->string('ics_to_position')->nullable()->after('addressee_phone');
            $table->string('ics_from_position')->nullable()->after('ics_to_position');
            $table->string('ics_subject')->nullable()->after('ics_from_position');
            $table->text('ics_reply_text')->nullable()->after('notes');
            $table->dateTime('ics_reply_date')->nullable()->after('ics_reply_text');
            $table->string('ics_reply_name')->nullable()->after('ics_reply_date');
            $table->string('ics_reply_position')->nullable()->after('ics_reply_name');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn([
                'ics_to_position', 'ics_from_position', 'ics_subject',
                'ics_reply_text', 'ics_reply_date', 'ics_reply_name', 'ics_reply_position',
            ]);

            $table->string('precedence')->nullable(false)->default('routine')->change();
            $table->string('station_of_origin')->nullable(false)->change();
            $table->string('check')->nullable(false)->change();
            $table->string('place_of_origin')->nullable(false)->change();
        });
    }
};
