<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_configurations', function (Blueprint $table) {
            $table->string('grid_square', 6)->nullable()->after('guestbook_local_subnets');
            $table->decimal('latitude', 10, 7)->nullable()->after('grid_square');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('city', 100)->nullable()->after('longitude');
            $table->string('state', 100)->nullable()->after('city');
        });

        // Copy existing guestbook lat/lon into the new shared location columns
        \Illuminate\Support\Facades\DB::statement('
            UPDATE event_configurations
            SET latitude = guestbook_latitude,
                longitude = guestbook_longitude
            WHERE guestbook_latitude IS NOT NULL
               OR guestbook_longitude IS NOT NULL
        ');

        Schema::table('event_configurations', function (Blueprint $table) {
            $table->dropColumn(['guestbook_latitude', 'guestbook_longitude']);
        });
    }

    public function down(): void
    {
        Schema::table('event_configurations', function (Blueprint $table) {
            $table->decimal('guestbook_latitude', 10, 7)->nullable();
            $table->decimal('guestbook_longitude', 10, 7)->nullable();
        });

        \Illuminate\Support\Facades\DB::statement('
            UPDATE event_configurations
            SET guestbook_latitude = latitude,
                guestbook_longitude = longitude
            WHERE latitude IS NOT NULL
               OR longitude IS NOT NULL
        ');

        Schema::table('event_configurations', function (Blueprint $table) {
            $table->dropColumn(['grid_square', 'latitude', 'longitude', 'city', 'state']);
        });
    }
};
