<?php

// Seeds an active event, station, operating session and contacts for the mobile browser audit.
// Run inside the app container after migrate:fresh --seeder=DevSeeder: php /data/setup.php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Band, Contact, Event, EventConfiguration, Mode, OperatingSession, Section, Station, User};
use Illuminate\Support\Facades\DB;

DB::table('system_config')->updateOrInsert(['key' => 'setup_completed'], ['value' => 'true']);
$user = User::where('call_sign', 'W1AW')->firstOrFail();
$user->forceFill(['password_changed_at' => now()])->save();
// Every page loads for the audit user; use the dev role switcher (ROLE=...) to audit other roles.
$user->syncRoles(['System Administrator']);
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
$event = Event::factory()->create(['name' => 'Field Day 2026 Mobile Test', 'start_time' => now()->subHours(6), 'end_time' => now()->addHours(18)]);
$config = EventConfiguration::factory()->create(['event_id' => $event->id, 'callsign' => 'W1AW', 'section_id' => Section::where('code', 'CT')->value('id'), 'transmitter_count' => 3, 'is_active' => true]);
$station = Station::factory()->create(['event_configuration_id' => $config->id, 'name' => 'Phone 1']);
$session = OperatingSession::factory()->active()->create([
    'station_id' => $station->id, 'operator_user_id' => $user->id,
    'band_id' => Band::where('name', '20m')->value('id'), 'mode_id' => Mode::where('name', 'Phone')->value('id'),
    'power_watts' => 100, 'qso_count' => 0,
]);
foreach (['K1ABC', 'N2XYZ', 'W3LONGCALL/QRP', 'VE3AAA', 'KD9QRS'] as $call) {
    Contact::factory()->create(['event_configuration_id' => $config->id, 'operating_session_id' => $session->id, 'callsign' => $call, 'logger_user_id' => $user->id]);
}
echo "session={$session->id} event={$event->id}\n";
