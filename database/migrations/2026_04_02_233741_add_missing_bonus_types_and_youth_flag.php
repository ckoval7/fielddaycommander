<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add is_youth to users table
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_youth')->default(false)->after('notification_preferences');
        });

        // 2. Look up FD event type ID
        $fdEventTypeId = DB::table('event_types')->where('code', 'FD')->value('id');

        if (! $fdEventTypeId) {
            return;
        }

        // 3. Fix eligible classes on existing bonus types
        DB::table('bonus_types')->where('code', 'emergency_power')
            ->update(['eligible_classes' => json_encode(['A', 'B', 'C', 'E', 'F'])]);

        DB::table('bonus_types')->where('code', 'public_location')
            ->update(['eligible_classes' => json_encode(['A', 'B', 'F'])]);

        DB::table('bonus_types')->where('code', 'public_info_booth')
            ->update(['eligible_classes' => json_encode(['A', 'B', 'F'])]);

        DB::table('bonus_types')->where('code', 'satellite_qso')
            ->update(['eligible_classes' => json_encode(['A', 'B', 'F'])]);

        DB::table('bonus_types')->where('code', 'natural_power')
            ->update(['eligible_classes' => json_encode(['A', 'B', 'E', 'F'])]);

        DB::table('bonus_types')->where('code', 'safety_officer')
            ->update(['eligible_classes' => json_encode(['A'])]);

        // 4. Fix media_publicity
        DB::table('bonus_types')->where('code', 'media_publicity')
            ->update([
                'is_per_occurrence' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
            ]);

        // 5. Deactivate old site_visit bonus type
        DB::table('bonus_types')->where('code', 'site_visit')
            ->update(['is_active' => false]);

        // 6. Insert new bonus types (idempotent via updateOrInsert)
        DB::table('bonus_types')->updateOrInsert(
            ['code' => 'elected_official_visit'],
            [
                'event_type_id' => $fdEventTypeId,
                'name' => 'Elected Official Visit',
                'description' => 'Visit by elected government official to Field Day site',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
                'is_active' => true,
            ]
        );

        DB::table('bonus_types')->updateOrInsert(
            ['code' => 'agency_visit'],
            [
                'event_type_id' => $fdEventTypeId,
                'name' => 'Agency Visit',
                'description' => 'Visit by served agency official to Field Day site',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
                'is_active' => true,
            ]
        );

        DB::table('bonus_types')->updateOrInsert(
            ['code' => 'educational_activity'],
            [
                'event_type_id' => $fdEventTypeId,
                'name' => 'Educational Activity',
                'description' => 'Conduct a STEM-related educational activity during Field Day',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => json_encode(['A', 'F', 'D', 'E']),
                'is_active' => true,
            ]
        );

        DB::table('bonus_types')->updateOrInsert(
            ['code' => 'web_submission'],
            [
                'event_type_id' => $fdEventTypeId,
                'name' => 'Web Submission',
                'description' => 'Submit Field Day entry via the ARRL web applet',
                'base_points' => 50,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 50,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
                'is_active' => true,
            ]
        );

        DB::table('bonus_types')->updateOrInsert(
            ['code' => 'youth_participation'],
            [
                'event_type_id' => $fdEventTypeId,
                'name' => 'Youth Participation',
                'description' => 'Youth element participation (20 points per youth, max 5)',
                'base_points' => 20,
                'is_per_transmitter' => false,
                'is_per_occurrence' => true,
                'max_points' => 100,
                'max_occurrences' => 5,
                'requires_proof' => false,
                'eligible_classes' => json_encode(['A', 'B', 'C', 'D', 'E', 'F']),
                'is_active' => true,
            ]
        );

        DB::table('bonus_types')->updateOrInsert(
            ['code' => 'site_responsibilities'],
            [
                'event_type_id' => $fdEventTypeId,
                'name' => 'Site Responsibilities',
                'description' => 'Assume site responsibilities for Field Day location',
                'base_points' => 50,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 50,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => json_encode(['B', 'C', 'D', 'E', 'F']),
                'is_active' => true,
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove new bonus types
        DB::table('bonus_types')->whereIn('code', [
            'elected_official_visit',
            'agency_visit',
            'educational_activity',
            'web_submission',
            'youth_participation',
            'site_responsibilities',
        ])->delete();

        // Re-activate site_visit
        DB::table('bonus_types')->where('code', 'site_visit')
            ->update(['is_active' => true]);

        // Revert media_publicity
        DB::table('bonus_types')->where('code', 'media_publicity')
            ->update([
                'is_per_occurrence' => true,
                'max_points' => null,
                'max_occurrences' => null,
            ]);

        // Revert eligible classes
        DB::table('bonus_types')->where('code', 'emergency_power')
            ->update(['eligible_classes' => json_encode(['A', 'D', 'E', 'F'])]);

        DB::table('bonus_types')->where('code', 'public_location')
            ->update(['eligible_classes' => json_encode(['A', 'F'])]);

        DB::table('bonus_types')->where('code', 'public_info_booth')
            ->update(['eligible_classes' => null]);

        DB::table('bonus_types')->where('code', 'satellite_qso')
            ->update(['eligible_classes' => null]);

        DB::table('bonus_types')->where('code', 'natural_power')
            ->update(['eligible_classes' => null]);

        DB::table('bonus_types')->where('code', 'safety_officer')
            ->update(['eligible_classes' => json_encode(['A', 'F'])]);

        // Remove is_youth from users table
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_youth');
        });
    }
};
