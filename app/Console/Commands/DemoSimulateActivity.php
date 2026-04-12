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

            if (! preg_match('/^demo_[a-f0-9_]{32,40}$/', $dbName)) {
                continue;
            }

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

        $usSections = $sections->filter(fn (Section $s) => $s->country === 'US');
        $canadianSections = $sections->filter(fn (Section $s) => $s->country === 'CA');

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

            // Match callsign nationality to section
            $isCanadian = random_int(1, 100) > 85;

            if ($isCanadian && $canadianSections->isNotEmpty()) {
                $callsign = CallsignGenerator::canada();
                $section = $canadianSections->random();
            } else {
                $callsign = CallsignGenerator::us();
                $section = $usSections->isNotEmpty() ? $usSections->random() : $sections->random();
            }
            $classPool = array_merge(
                array_fill(0, 50, 'A'),
                array_fill(0, 20, 'B'),
                array_fill(0, 15, 'C'),
                array_fill(0, 10, 'D'),
                array_fill(0, 4, 'E'),
                array_fill(0, 1, 'F'),
            );
            $fdClassLetter = $classPool[array_rand($classPool)];
            $transmitterCount = match ($fdClassLetter) {
                'A' => random_int(1, 20),
                'B' => random_int(1, 2),
                'F' => random_int(2, 10),
                default => 1,
            };
            $fdClass = $transmitterCount.$fdClassLetter;

            $contact = Contact::create([
                'event_configuration_id' => $config->id,
                'operating_session_id' => $session->id,
                'logger_user_id' => $session->operator_user_id,
                'band_id' => $session->band_id,
                'mode_id' => $session->mode_id,
                'qso_time' => now(),
                'callsign' => $callsign,
                'section_id' => $section->id,
                'exchange_class' => $fdClass,
                'power_watts' => $session->power_watts ?? 100,
                'is_gota_contact' => $session->station->is_gota,
                'is_natural_power' => false,
                'is_satellite' => false,
                'points' => 1,
                'is_duplicate' => false,
                'notes' => null,
            ]);

            event(new ContactLogged($contact, $event));
            $logged++;
        }

        return $logged;
    }
}
