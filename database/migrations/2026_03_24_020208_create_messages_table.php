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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->constrained('event_configurations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('format');           // MessageFormat enum
            $table->string('role');             // MessageRole enum
            $table->boolean('is_sm_message')->default(false);
            $table->integer('message_number');
            $table->string('precedence')->nullable()->default('routine'); // MessagePrecedence enum
            $table->string('hx_code')->nullable();            // HxCode enum
            $table->string('hx_value', 20)->nullable();
            $table->string('station_of_origin')->nullable();
            $table->string('check')->nullable();            // String for "ARL 10" format
            $table->string('place_of_origin')->nullable();
            $table->dateTime('filed_at')->nullable();
            $table->string('addressee_name');
            $table->string('addressee_address')->nullable();
            $table->string('addressee_city')->nullable();
            $table->string('addressee_state')->nullable();
            $table->string('addressee_zip')->nullable();
            $table->string('addressee_phone')->nullable();
            $table->string('ics_to_position')->nullable();
            $table->string('ics_from_position')->nullable();
            $table->string('ics_subject')->nullable();
            $table->text('message_text');
            $table->string('signature');
            $table->string('sent_to')->nullable();
            $table->string('received_from')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users');
            $table->string('frequency', 15)->nullable();
            $table->string('mode_category')->nullable();
            $table->text('notes')->nullable();
            $table->text('ics_reply_text')->nullable();
            $table->dateTime('ics_reply_date')->nullable();
            $table->string('ics_reply_name')->nullable();
            $table->string('ics_reply_position')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('event_configuration_id');
            $table->index('is_sm_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
