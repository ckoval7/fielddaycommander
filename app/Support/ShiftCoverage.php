<?php

namespace App\Support;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Computes staffing coverage, coverage gaps, and Gantt layout for an event's shifts.
 *
 * Callers must pass shifts with the `assignments` relation eager-loaded. An assignment
 * counts toward coverage unless it is marked as a no-show.
 */
class ShiftCoverage
{
    public const GAP_UNSTAFFED = 'unstaffed';

    public const GAP_UNDERSTAFFED = 'understaffed';

    public const GAP_UNSCHEDULED = 'unscheduled';

    /**
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<int, ShiftRole>  $roles  Roles in display order.
     */
    public function __construct(
        public readonly Collection $shifts,
        public readonly Collection $roles,
        public readonly CarbonImmutable $windowStart,
        public readonly CarbonImmutable $windowEnd,
    ) {}

    /**
     * Build a coverage instance whose window spans the event and every shift.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<int, ShiftRole>  $roles
     */
    public static function forWindow(Collection $shifts, Collection $roles, ?CarbonInterface $eventStart, ?CarbonInterface $eventEnd): ?self
    {
        $starts = $shifts->pluck('start_time')->push($eventStart)->filter();
        $ends = $shifts->pluck('end_time')->push($eventEnd)->filter();

        if ($starts->isEmpty() || $ends->isEmpty()) {
            return null;
        }

        $windowStart = CarbonImmutable::createFromTimestamp($starts->min(fn (CarbonInterface $t) => $t->getTimestamp()), 'UTC');
        $windowEnd = CarbonImmutable::createFromTimestamp($ends->max(fn (CarbonInterface $t) => $t->getTimestamp()), 'UTC');

        if ($windowEnd->lessThanOrEqualTo($windowStart)) {
            return null;
        }

        return new self($shifts, $roles, $windowStart, $windowEnd);
    }

    /**
     * Number of assignments on a shift that count toward coverage.
     */
    public static function staffedCount(Shift $shift): int
    {
        return $shift->assignments
            ->where('status', '!=', ShiftAssignment::STATUS_NO_SHOW)
            ->count();
    }

    /**
     * Number of capacity slots on a shift that are filled.
     */
    public static function filledSlots(Shift $shift): int
    {
        return min(self::staffedCount($shift), $shift->capacity);
    }

    /**
     * Event-wide coverage totals.
     *
     * @return array{shift_count: int, total_slots: int, filled_slots: int, open_slots: int, full_shifts: int, empty_shifts: int, on_duty: int, coverage_percent: int}
     */
    public function summary(): array
    {
        $totalSlots = (int) $this->shifts->sum('capacity');
        $filledSlots = (int) $this->shifts->sum(fn (Shift $shift) => self::filledSlots($shift));

        return [
            'shift_count' => $this->shifts->count(),
            'total_slots' => $totalSlots,
            'filled_slots' => $filledSlots,
            'open_slots' => $totalSlots - $filledSlots,
            'full_shifts' => $this->shifts->filter(fn (Shift $shift) => self::filledSlots($shift) >= $shift->capacity)->count(),
            'empty_shifts' => $this->shifts->filter(fn (Shift $shift) => self::staffedCount($shift) === 0)->count(),
            'on_duty' => $this->shifts
                ->flatMap(fn (Shift $shift) => $shift->assignments)
                ->where('status', ShiftAssignment::STATUS_CHECKED_IN)
                ->pluck('user_id')
                ->unique()
                ->count(),
            'coverage_percent' => $this->percent(
                $this->shifts->sum(fn (Shift $shift) => self::filledSlots($shift) * $this->hours($shift)),
                $this->shifts->sum(fn (Shift $shift) => $shift->capacity * $this->hours($shift)),
            ),
        ];
    }

    /**
     * Coverage broken down by role, weighted by shift length.
     *
     * @return array<int, array{role: ShiftRole, shift_count: int, total_slots: int, filled_slots: int, open_slots: int, coverage_percent: int}>
     */
    public function roleCoverage(): array
    {
        return $this->rolesWithShifts()
            ->map(function (ShiftRole $role, int|string $roleId) {
                $roleShifts = $this->shiftsForRole($role);
                $totalSlots = (int) $roleShifts->sum('capacity');
                $filledSlots = (int) $roleShifts->sum(fn (Shift $shift) => self::filledSlots($shift));

                return [
                    'role' => $role,
                    'shift_count' => $roleShifts->count(),
                    'total_slots' => $totalSlots,
                    'filled_slots' => $filledSlots,
                    'open_slots' => $totalSlots - $filledSlots,
                    'coverage_percent' => $this->percent(
                        $roleShifts->sum(fn (Shift $shift) => self::filledSlots($shift) * $this->hours($shift)),
                        $roleShifts->sum(fn (Shift $shift) => $shift->capacity * $this->hours($shift)),
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Periods within each role's scheduled span where nobody is staffed, ordered by start time.
     *
     * A gap is "unstaffed" when a shift exists but has no one on it, "understaffed" when the
     * shifts there are filled below half their combined capacity, and "unscheduled" when no
     * shift for the role exists between its first and last shift. Staffing gaps carry the
     * shifts with open slots they cover and are split wherever those shifts change.
     *
     * @return array<int, array{role: ShiftRole, type: string, start: CarbonImmutable, end: CarbonImmutable, minutes: int, shifts: array<int, Shift>}>
     */
    public function gaps(): array
    {
        return $this->rolesWithShifts()
            ->flatMap(fn (ShiftRole $role) => $this->gapsForRole($role))
            ->sortBy(fn (array $gap) => $gap['start']->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * Gantt rows, one per role, with shifts packed into non-overlapping lanes.
     *
     * @return array<int, array{role: ShiftRole, lanes: array<int, array<int, Shift>>, gaps: array<int, array{role: ShiftRole, type: string, start: CarbonImmutable, end: CarbonImmutable, minutes: int, shifts: array<int, Shift>}>}>
     */
    public function ganttRows(): array
    {
        return $this->rolesWithShifts()
            ->map(fn (ShiftRole $role) => [
                'role' => $role,
                'lanes' => $this->packLanes($this->shiftsForRole($role)),
                'gaps' => $this->gapsForRole($role),
            ])
            ->values()
            ->all();
    }

    /**
     * Horizontal offset of a timestamp within the window, as a percentage.
     */
    public function offsetPercent(CarbonInterface $time): float
    {
        $span = $this->windowEnd->getTimestamp() - $this->windowStart->getTimestamp();
        $offset = $time->getTimestamp() - $this->windowStart->getTimestamp();

        return round(max(0, min(100, $offset / $span * 100)), 4);
    }

    /**
     * Width of a time range within the window, as a percentage.
     */
    public function widthPercent(CarbonInterface $start, CarbonInterface $end): float
    {
        return round(max(0, $this->offsetPercent($end) - $this->offsetPercent($start)), 4);
    }

    /**
     * Hourly tick marks across the window, labelled every $labelEveryHours hours.
     *
     * @return array<int, array{time: CarbonImmutable, offset: float, labelled: bool}>
     */
    public function hourTicks(): array
    {
        $totalHours = $this->windowStart->diffInHours($this->windowEnd);
        $labelEveryHours = match (true) {
            $totalHours <= 12 => 1,
            $totalHours <= 30 => 2,
            $totalHours <= 60 => 4,
            default => 6,
        };

        $ticks = [];
        $tick = $this->windowStart->startOfHour();
        if ($tick->lessThan($this->windowStart)) {
            $tick = $tick->addHour();
        }

        while ($tick->lessThanOrEqualTo($this->windowEnd)) {
            $ticks[] = [
                'time' => $tick,
                'offset' => $this->offsetPercent($tick),
                'labelled' => $tick->getTimestamp() % ($labelEveryHours * 3600) === 0,
            ];
            $tick = $tick->addHour();
        }

        return $ticks;
    }

    /**
     * @return Collection<int, ShiftRole>
     */
    private function rolesWithShifts(): Collection
    {
        $roleIds = $this->shifts->pluck('shift_role_id')->unique();

        return $this->roles
            ->filter(fn (ShiftRole $role) => $roleIds->contains($role->id))
            ->values();
    }

    /**
     * @return Collection<int, Shift>
     */
    private function shiftsForRole(ShiftRole $role): Collection
    {
        return $this->shifts
            ->where('shift_role_id', $role->id)
            ->sortBy(fn (Shift $shift) => $shift->start_time->getTimestamp())
            ->values();
    }

    /**
     * @return array<int, array{role: ShiftRole, type: string, start: CarbonImmutable, end: CarbonImmutable, minutes: int, shifts: array<int, Shift>}>
     */
    private function gapsForRole(ShiftRole $role): array
    {
        $roleShifts = $this->shiftsForRole($role);

        $boundaries = $roleShifts
            ->flatMap(fn (Shift $shift) => [$shift->start_time->getTimestamp(), $shift->end_time->getTimestamp()])
            ->unique()
            ->sort()
            ->values();

        $gaps = [];
        for ($i = 0; $i < $boundaries->count() - 1; $i++) {
            $segmentStart = $boundaries[$i];
            $segmentEnd = $boundaries[$i + 1];
            $midpoint = ($segmentStart + $segmentEnd) / 2;

            $overlapping = $roleShifts->filter(
                fn (Shift $shift) => $shift->start_time->getTimestamp() <= $midpoint && $shift->end_time->getTimestamp() > $midpoint
            );

            $staffed = $overlapping->sum(fn (Shift $shift) => self::staffedCount($shift));
            $capacity = $overlapping->sum('capacity');

            $type = match (true) {
                $overlapping->isEmpty() => self::GAP_UNSCHEDULED,
                $staffed === 0 => self::GAP_UNSTAFFED,
                $staffed * 2 < $capacity => self::GAP_UNDERSTAFFED,
                default => null,
            };

            if ($type === null) {
                continue;
            }

            $shifts = $overlapping
                ->filter(fn (Shift $shift) => self::staffedCount($shift) < $shift->capacity)
                ->values()
                ->all();
            $last = array_key_last($gaps);

            if ($last !== null
                && $gaps[$last]['type'] === $type
                && $gaps[$last]['end'] === $segmentStart
                && $gaps[$last]['shifts'] === $shifts) {
                $gaps[$last]['end'] = $segmentEnd;
            } else {
                $gaps[] = ['type' => $type, 'start' => $segmentStart, 'end' => $segmentEnd, 'shifts' => $shifts];
            }
        }

        return array_map(fn (array $gap) => [
            'role' => $role,
            'type' => $gap['type'],
            'start' => CarbonImmutable::createFromTimestamp($gap['start'], 'UTC'),
            'end' => CarbonImmutable::createFromTimestamp($gap['end'], 'UTC'),
            'minutes' => intdiv($gap['end'] - $gap['start'], 60),
            'shifts' => $gap['shifts'],
        ], $gaps);
    }

    /**
     * Greedily place shifts (sorted by start) into the first lane they don't overlap.
     *
     * @param  Collection<int, Shift>  $shifts
     * @return array<int, array<int, Shift>>
     */
    private function packLanes(Collection $shifts): array
    {
        $lanes = [];
        $laneEnds = [];

        foreach ($shifts as $shift) {
            $placed = false;

            foreach ($laneEnds as $index => $laneEnd) {
                if ($shift->start_time->getTimestamp() >= $laneEnd) {
                    $lanes[$index][] = $shift;
                    $laneEnds[$index] = $shift->end_time->getTimestamp();
                    $placed = true;
                    break;
                }
            }

            if (! $placed) {
                $lanes[] = [$shift];
                $laneEnds[] = $shift->end_time->getTimestamp();
            }
        }

        return $lanes;
    }

    private function hours(Shift $shift): float
    {
        return max(0, $shift->end_time->getTimestamp() - $shift->start_time->getTimestamp()) / 3600;
    }

    private function percent(float|int $part, float|int $whole): int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : 0;
    }
}
