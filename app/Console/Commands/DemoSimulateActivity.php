<?php

namespace App\Console\Commands;

use App\Events\ContactLogged;
use App\Models\Contact;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\ShiftAssignment;
use App\Models\Station;
use App\Support\CallsignGenerator;
use App\Support\DemoDatabase;
use App\Support\TransmitterCountGenerator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
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

            if (! DemoDatabase::isVisitorDatabase($dbName)) {
                continue;
            }

            try {
                DemoDatabase::activate($dbName);

                $provisionedAt = DB::connection('demo')
                    ->table('system_config')
                    ->where('key', 'demo_provisioned_at')
                    ->value('value');

                if (! $provisionedAt || Carbon::parse($provisionedAt)->addHours($ttlHours)->isPast()) {
                    continue;
                }

                $logged = $this->simulateForDatabase($dbName, $ttlHours);
                $totalLogged += $logged;

                $this->simulateShiftAttendance($dbName);

            } catch (\Throwable $e) {
                $this->warn("Could not simulate for {$dbName}: {$e->getMessage()}");
            }
        }

        if ($totalLogged > 0) {
            $this->info("Logged {$totalLogged} simulated contact(s) across active demo sessions.");
        }

        return self::SUCCESS;
    }

    public function simulateForDatabase(string $dbName, int $ttlHours): int
    {
        $this->rotateOperators($dbName, $ttlHours);

        $simulatedIds = $this->resolveSimulatedSessionIds($dbName, $ttlHours);

        if (empty($simulatedIds)) {
            return 0;
        }

        $activeSessions = OperatingSession::active()
            ->whereIn('id', $simulatedIds)
            ->whereHas('station', fn ($q) => $q->where('is_gota', false))
            ->with(['station.eventConfiguration.event.eventType', 'band', 'mode'])
            ->get();

        $logged = 0;
        $sections = Section::where('is_active', true)->get();

        if ($sections->isEmpty()) {
            return 0;
        }

        $usSections = $sections->filter(fn (Section $s) => $s->country === 'US');
        $canadianSections = $sections->filter(fn (Section $s) => $s->country === 'CA');

        foreach ($activeSessions as $session) {
            // ~25% chance per tick (~15 QSOs/hour per station), in line with
            // the rate the seeded history was logged at
            if (random_int(1, 100) > 25) {
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
            $isWinter = $event->eventType?->code === 'WFD';
            $exchangeClass = $this->makeExchangeClass($isWinter);

            // Score each simulated QSO by its mode under the event's own
            // rulebook, rather than assuming Field Day's values.
            $points = (int) ($isWinter
                ? ($session->mode?->points_wfd ?? 1)
                : ($session->mode?->points_fd ?? 1));

            $contact = Contact::create([
                'event_configuration_id' => $config->id,
                'operating_session_id' => $session->id,
                'logger_user_id' => $session->operator_user_id,
                'band_id' => $session->band_id,
                'mode_id' => $session->mode_id,
                'qso_time' => now(),
                'callsign' => $callsign,
                'section_id' => $section->id,
                'exchange_class' => $exchangeClass,
                'power_watts' => $session->power_watts ?? 100,
                'is_gota_contact' => $session->station->is_gota,
                'is_natural_power' => false,
                'is_satellite' => false,
                'points' => $points,
                'is_duplicate' => false,
                'notes' => null,
            ]);

            event(new ContactLogged($contact, $event));
            $logged++;
        }

        return $logged;
    }

    /**
     * Hand the simulated HF stations from operator to operator as shifts turn
     * over.
     *
     * An operator keeps their station while they are checked in to a current
     * Operator shift. Once they are off duty their session ends and, if an
     * on-duty operator is free, that operator takes the station over. An idle
     * station is picked up now and then by a free on-duty operator, the way a
     * station gets reopened after a break. Stations with a session the
     * visitor started are left alone, and only simulated volunteers are
     * brought on.
     *
     * @return array{ended: int, started: int}
     */
    public function rotateOperators(string $dbName, int $ttlHours): array
    {
        $counts = ['ended' => 0, 'started' => 0];
        $stationIds = self::cacheStore()->get(self::stationCacheKey($dbName), []);

        if (empty($stationIds)) {
            return $counts;
        }

        $simulatedSessionIds = array_map('intval', $this->resolveSimulatedSessionIds($dbName, $ttlHours));
        $now = now();

        $onDutyUserIds = ShiftAssignment::whereIn('id', self::cacheStore()->get(self::shiftCacheKey($dbName), []))
            ->where('status', ShiftAssignment::STATUS_CHECKED_IN)
            ->whereHas('shift', fn ($q) => $q->where('start_time', '<=', $now)
                ->where('end_time', '>', $now)
                ->whereHas('shiftRole', fn ($r) => $r->where('name', 'Operator')))
            ->pluck('user_id')
            ->unique();

        $operatingUserIds = OperatingSession::active()->pluck('operator_user_id');
        $freeOperatorIds = $onDutyUserIds->diff($operatingUserIds)->shuffle()->values();

        foreach (Station::whereIn('id', $stationIds)->get() as $station) {
            $openSession = OperatingSession::active()->where('station_id', $station->id)->first();

            if ($openSession && ! in_array($openSession->id, $simulatedSessionIds, true)) {
                continue;
            }

            if ($openSession && $onDutyUserIds->contains($openSession->operator_user_id)) {
                continue;
            }

            if ($openSession) {
                $openSession->update([
                    'end_time' => $now,
                    'qso_count' => $openSession->contacts()->count(),
                ]);
                $counts['ended']++;
            } elseif (random_int(1, 100) > 10) {
                continue;
            }

            $incomingId = $freeOperatorIds->shift();
            $lastSession = $openSession ?? OperatingSession::where('station_id', $station->id)->latest('start_time')->first();

            if (! $incomingId || ! $lastSession) {
                continue;
            }

            $session = OperatingSession::create([
                'station_id' => $station->id,
                'operator_user_id' => $incomingId,
                'band_id' => $lastSession->band_id,
                'mode_id' => $lastSession->mode_id,
                'start_time' => $now,
                'end_time' => null,
                'power_watts' => $lastSession->power_watts ?? 100,
                'qso_count' => 0,
                'is_transcription' => false,
                'is_supervised' => false,
            ]);

            $simulatedSessionIds[] = $session->id;
            $counts['started']++;
        }

        self::registerSeededSessions($dbName, $simulatedSessionIds, $ttlHours);

        return $counts;
    }

    /**
     * Check simulated volunteers in and out around their shift boundaries.
     *
     * Each tick gives a volunteer a chance to arrive from 15 minutes before
     * their shift starts and to leave from 5 minutes before it ends, so
     * arrivals and departures trickle in instead of landing on the exact
     * minute. Anyone still pending 10 minutes past the boundary is moved
     * along so the schedule never stalls. Only seeded assignments for
     * non-visitor users are touched; the visitor's own shifts are theirs.
     *
     * @return array{checked_in: int, checked_out: int}
     */
    public function simulateShiftAttendance(string $dbName): array
    {
        $assignmentIds = self::cacheStore()->get(self::shiftCacheKey($dbName), []);
        $counts = ['checked_in' => 0, 'checked_out' => 0];

        if (empty($assignmentIds)) {
            return $counts;
        }

        $now = now();

        $assignments = ShiftAssignment::whereIn('id', $assignmentIds)
            ->whereIn('status', [ShiftAssignment::STATUS_SCHEDULED, ShiftAssignment::STATUS_CHECKED_IN])
            ->with('shift')
            ->get();

        foreach ($assignments as $assignment) {
            $shift = $assignment->shift;

            if (! $shift) {
                continue;
            }

            if ($assignment->status === ShiftAssignment::STATUS_SCHEDULED) {
                $arrivalOpens = $shift->start_time->copy()->subMinutes(15);

                if ($now->lt($arrivalOpens) || $now->gte($shift->end_time)) {
                    continue;
                }

                $isOverdue = $now->gte($shift->start_time->copy()->addMinutes(10));

                if ($isOverdue || random_int(1, 100) <= 30) {
                    $assignment->checkIn();
                    $counts['checked_in']++;
                }

                continue;
            }

            if ($now->lt($shift->end_time->copy()->subMinutes(5))) {
                continue;
            }

            $isOverdue = $now->gte($shift->end_time->copy()->addMinutes(10));

            if ($isOverdue || random_int(1, 100) <= 30) {
                $assignment->checkOut();
                $counts['checked_out']++;
            }
        }

        return $counts;
    }

    /**
     * Build a plausible received exchange class for the event being simulated.
     *
     * Field Day sends classes A-F; Winter Field Day sends H (home), I (indoor),
     * O (outdoor) and M (mobile). Both prefix a transmitter count.
     */
    private function makeExchangeClass(bool $isWinter): string
    {
        $classPool = $isWinter
            ? array_merge(
                array_fill(0, 55, 'H'),
                array_fill(0, 20, 'O'),
                array_fill(0, 15, 'I'),
                array_fill(0, 10, 'M'),
            )
            : array_merge(
                array_fill(0, 50, 'A'),
                array_fill(0, 20, 'B'),
                array_fill(0, 15, 'C'),
                array_fill(0, 10, 'D'),
                array_fill(0, 4, 'E'),
                array_fill(0, 1, 'F'),
            );

        $letter = $classPool[array_rand($classPool)];

        return TransmitterCountGenerator::forClass($letter).$letter;
    }

    /**
     * Snapshot the seeded operating-session IDs for a demo database the first
     * time we see it, then keep using that snapshot. Sessions a visitor starts
     * later in the UI are never adopted by the simulator.
     *
     * @return array<int, int>
     */
    private function resolveSimulatedSessionIds(string $dbName, int $ttlHours): array
    {
        return self::cacheStore()->remember(
            self::cacheKey($dbName),
            now()->addHours($ttlHours),
            fn (): array => OperatingSession::active()
                ->whereHas('station', fn ($q) => $q->where('is_gota', false))
                ->pluck('id')
                ->all(),
        );
    }

    /**
     * Register the seeded session IDs for a freshly provisioned demo DB so the
     * simulator never races against a visitor who starts their own session
     * before the first cron tick.
     *
     * @param  array<int, int>  $sessionIds
     */
    public static function registerSeededSessions(string $dbName, array $sessionIds, int $ttlHours): void
    {
        self::cacheStore()->put(
            self::cacheKey($dbName),
            array_values($sessionIds),
            now()->addHours($ttlHours),
        );
    }

    /**
     * Register the seeded shift assignments the simulator may check in and
     * out. The provisioner leaves out the visitor's own users so their
     * shifts wait for them to act.
     *
     * @param  array<int, int>  $assignmentIds
     */
    public static function registerSeededShiftAssignments(string $dbName, array $assignmentIds, int $ttlHours): void
    {
        self::cacheStore()->put(
            self::shiftCacheKey($dbName),
            array_values($assignmentIds),
            now()->addHours($ttlHours),
        );
    }

    /**
     * Register the seeded HF stations the simulator staffs from the operator
     * rotation.
     *
     * @param  array<int, int>  $stationIds
     */
    public static function registerSeededStations(string $dbName, array $stationIds, int $ttlHours): void
    {
        self::cacheStore()->put(
            self::stationCacheKey($dbName),
            array_values($stationIds),
            now()->addHours($ttlHours),
        );
    }

    private static function cacheStore(): Repository
    {
        return Cache::store(config('demo.simulator_cache_store'));
    }

    private static function cacheKey(string $dbName): string
    {
        return "demo:{$dbName}:simulated_session_ids";
    }

    private static function stationCacheKey(string $dbName): string
    {
        return "demo:{$dbName}:simulated_station_ids";
    }

    private static function shiftCacheKey(string $dbName): string
    {
        return "demo:{$dbName}:simulated_shift_assignment_ids";
    }
}
