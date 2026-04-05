<?php

namespace App\Console\Commands;

use App\Events\ContactLogged;
use App\Models\Contact;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Support\CallsignGenerator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class DemoSimulateActivity extends Command
{
    protected $signature = 'demo:simulate-activity';

    protected $description = 'Log simulated contacts to active demo sessions for live dashboard updates';

    public function handle(): int
    {
        if (! config('demo.enabled')) {
            $this->info('Demo mode is disabled.');

            return self::SUCCESS;
        }

        try {
            $rows = DB::select(
                "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'demo\_%'"
            );
        } catch (\Throwable) {
            // information_schema not available (e.g. SQLite in tests)
            $rows = [];
        }

        if (empty($rows)) {
            return self::SUCCESS;
        }

        $ttlHours = config('demo.ttl_hours', 24);
        $totalLogged = 0;

        foreach ($rows as $row) {
            $dbName = $row->schema_name ?? $row->SCHEMA_NAME;

            try {
                Config::set('database.connections.demo.database', $dbName);
                DB::purge('demo');

                $provisionedAt = DB::connection('demo')
                    ->table('system_config')
                    ->where('key', 'demo_provisioned_at')
                    ->value('value');

                if (! $provisionedAt || Carbon::parse($provisionedAt)->addHours($ttlHours)->isPast()) {
                    continue;
                }

                $logged = $this->simulateForDatabase();
                $totalLogged += $logged;

            } catch (\Throwable $e) {
                $this->warn("Could not simulate for {$dbName}: {$e->getMessage()}");
            }
        }

        if ($totalLogged > 0) {
            $this->info("Logged {$totalLogged} simulated contact(s) across active demo sessions.");
        }

        return self::SUCCESS;
    }

    private function simulateForDatabase(): int
    {
        $activeSessions = OperatingSession::active()
            ->with(['station.eventConfiguration.event', 'band', 'mode'])
            ->get();

        $logged = 0;
        $sections = Section::where('is_active', true)->get();

        if ($sections->isEmpty()) {
            return 0;
        }

        foreach ($activeSessions as $session) {
            // ~40% chance to log a contact this tick per active session
            if (random_int(1, 100) > 40) {
                continue;
            }

            $config = $session->station->eventConfiguration;
            $event = $config->event;

            if (! $event || ! $event->is_active) {
                continue;
            }

            $section = $sections->random();
            $callsign = CallsignGenerator::any();
            $fdClass = random_int(1, 5).fake()->randomElement(['A', 'B', 'C', 'D', 'E']);

            $contact = Contact::create([
                'event_configuration_id' => $config->id,
                'operating_session_id' => $session->id,
                'logger_user_id' => $session->operator_user_id,
                'band_id' => $session->band_id,
                'mode_id' => $session->mode_id,
                'qso_time' => now(),
                'callsign' => $callsign,
                'section_id' => $section->id,
                'received_exchange' => "{$callsign} {$fdClass} {$section->code}",
                'power_watts' => $session->power_watts ?? 100,
                'is_gota_contact' => $session->station->is_gota,
                'is_natural_power' => false,
                'is_satellite' => false,
                'points' => 1,
                'is_duplicate' => false,
                'notes' => null,
            ]);

            ContactLogged::dispatch($contact, $event);
            $logged++;
        }

        return $logged;
    }
}
