<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Schedule\ScheduleTimeline;
use App\Livewire\Schedule\ShiftDashboard;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use App\Support\ShiftCoverage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-27 12:00:00', 'UTC'));

    $this->event = Event::factory()->create([
        'name' => 'Field Day 2026',
        'start_time' => Carbon::parse('2026-06-27 18:00:00', 'UTC'),
        'end_time' => Carbon::parse('2026-06-28 18:00:00', 'UTC'),
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    $this->user = User::factory()->create(['call_sign' => 'W1ABC']);

    Setting::set('time_format', 'H:i:s');

    $this->operator = ShiftRole::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Station Operator',
        'sort_order' => 1,
    ]);

    $this->makeShift = fn (string $start, string $end, int $capacity = 1, ?ShiftRole $role = null): Shift => Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => ($role ?? $this->operator)->id,
        'start_time' => Carbon::parse($start, 'UTC'),
        'end_time' => Carbon::parse($end, 'UTC'),
        'capacity' => $capacity,
    ]);

    $this->assign = fn (Shift $shift, string $status = ShiftAssignment::STATUS_SCHEDULED): ShiftAssignment => ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => User::factory()->create()->id,
        'status' => $status,
    ]);

    $this->coverage = fn (): ShiftCoverage => ShiftCoverage::forWindow(
        Shift::query()->forEvent($this->eventConfig->id)->with('assignments')->get(),
        ShiftRole::query()->forEvent($this->eventConfig->id)->ordered()->get(),
        $this->event->start_time,
        $this->event->end_time,
    );
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('page', function () {
    test('requires authentication', function () {
        $this->get(route('schedule.dashboard'))->assertRedirect(route('login'));
    });

    test('renders coverage, gaps, and timeline cards', function () {
        $shift = ($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00', 2);
        ($this->assign)($shift);

        $this->actingAs($this->user);

        Livewire::test(ShiftDashboard::class)
            ->assertOk()
            ->assertSee('Shift Dashboard')
            ->assertSee('Field Day 2026')
            ->assertSeeHtml('data-testid="coverage-card"')
            ->assertSeeHtml('data-testid="gaps-card"')
            ->assertSeeHtml('data-testid="gantt-card"')
            ->assertSeeHtml('data-testid="gantt-bar"')
            ->assertSeeHtmlInOrder(['data-testid="gantt-card"', 'data-testid="coverage-card"', 'data-testid="gaps-card"'])
            ->assertSee('Station Operator')
            ->assertSee('1/2');
    });

    test('shows empty state when no shifts exist', function () {
        $this->actingAs($this->user);

        Livewire::test(ShiftDashboard::class)
            ->assertSee('No shifts scheduled')
            ->assertDontSeeHtml('data-testid="gantt-card"');
    });

    test('lists upcoming gaps in the gaps card', function () {
        ($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00');

        $this->actingAs($this->user);

        Livewire::test(ShiftDashboard::class)
            ->assertSee('1 open')
            ->assertSee('2h');
    });

    test('labels empty and under-half shifts in the gaps card', function () {
        ($this->assign)(($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00', capacity: 3));
        ($this->makeShift)('2026-06-27 20:00', '2026-06-27 22:00');

        $this->actingAs($this->user);

        Livewire::test(ShiftDashboard::class)
            ->assertSee('2 open')
            ->assertSeeInOrder(['Under half', 'Empty']);
    });

    test('leaves time with no shift out of the gaps card', function () {
        ($this->assign)(($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00'));
        ($this->assign)(($this->makeShift)('2026-06-27 21:00', '2026-06-27 23:00'));

        $this->actingAs($this->user);

        Livewire::test(ShiftDashboard::class)
            ->assertSee('0 open')
            ->assertSee('No current or upcoming coverage gaps');
    });

    test('schedule page links to the dashboard', function () {
        $this->actingAs($this->user);

        Livewire::test(ScheduleTimeline::class)
            ->assertSee(route('schedule.dashboard'));
    });
});

describe('coverage', function () {
    test('weights coverage by shift length and ignores no-shows', function () {
        $long = ($this->makeShift)('2026-06-27 18:00', '2026-06-27 22:00', 2);
        $short = ($this->makeShift)('2026-06-27 22:00', '2026-06-27 23:00', 1);
        ($this->assign)($long);
        ($this->assign)($long, ShiftAssignment::STATUS_CHECKED_IN);
        ($this->assign)($short, ShiftAssignment::STATUS_NO_SHOW);

        $summary = ($this->coverage)()->summary();

        expect($summary['total_slots'])->toBe(3)
            ->and($summary['filled_slots'])->toBe(2)
            ->and($summary['open_slots'])->toBe(1)
            ->and($summary['full_shifts'])->toBe(1)
            ->and($summary['empty_shifts'])->toBe(1)
            ->and($summary['on_duty'])->toBe(1)
            // 8 filled slot-hours of 9 total
            ->and($summary['coverage_percent'])->toBe(89);
    });

    test('breaks coverage down per role and skips roles without shifts', function () {
        $safety = ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Safety Officer',
            'sort_order' => 2,
        ]);
        ShiftRole::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Unused Role',
            'sort_order' => 3,
        ]);

        ($this->assign)(($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00'));
        ($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00', 1, $safety);

        $roles = collect(($this->coverage)()->roleCoverage());

        expect($roles->pluck('role.name')->all())->toBe(['Station Operator', 'Safety Officer'])
            ->and($roles->pluck('coverage_percent')->all())->toBe([100, 0]);
    });
});

describe('gaps', function () {
    test('splits unstaffed and unscheduled gaps', function () {
        ($this->assign)(($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00'));
        ($this->makeShift)('2026-06-27 20:00', '2026-06-27 22:00');
        ($this->assign)(($this->makeShift)('2026-06-27 23:00', '2026-06-28 01:00'));

        $gaps = collect(($this->coverage)()->gaps())
            ->map(fn (array $gap) => [$gap['type'], $gap['start']->format('H:i'), $gap['end']->format('H:i'), $gap['minutes']])
            ->all();

        expect($gaps)->toBe([
            [ShiftCoverage::GAP_UNSTAFFED, '20:00', '22:00', 120],
            [ShiftCoverage::GAP_UNSCHEDULED, '22:00', '23:00', 60],
        ]);
    });

    test('splits unstaffed gaps per empty shift and carries the shift', function () {
        $first = ($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00');
        $second = ($this->makeShift)('2026-06-27 20:00', '2026-06-27 22:00');

        $gaps = collect(($this->coverage)()->gaps())
            ->map(fn (array $gap) => [$gap['start']->format('H:i'), $gap['end']->format('H:i'), collect($gap['shifts'])->pluck('id')->all()])
            ->all();

        expect($gaps)->toBe([
            ['18:00', '20:00', [$first->id]],
            ['20:00', '22:00', [$second->id]],
        ]);
    });

    test('marks shifts filled below half as understaffed', function () {
        $quarter = ($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00', capacity: 4);
        ($this->assign)($quarter);
        $half = ($this->makeShift)('2026-06-27 20:00', '2026-06-27 22:00', capacity: 4);
        ($this->assign)($half);
        ($this->assign)($half);
        ($this->makeShift)('2026-06-27 22:00', '2026-06-27 23:00', capacity: 4);

        $gaps = collect(($this->coverage)()->gaps())
            ->map(fn (array $gap) => [$gap['type'], $gap['start']->format('H:i'), $gap['end']->format('H:i')])
            ->all();

        expect($gaps)->toBe([
            [ShiftCoverage::GAP_UNDERSTAFFED, '18:00', '20:00'],
            [ShiftCoverage::GAP_UNSTAFFED, '22:00', '23:00'],
        ]);
    });

    test('an overlapping staffed shift covers an empty one', function () {
        ($this->assign)(($this->makeShift)('2026-06-27 18:00', '2026-06-27 22:00'));
        ($this->makeShift)('2026-06-27 19:00', '2026-06-27 21:00');

        expect(($this->coverage)()->gaps())->toBe([]);
    });

    test('a shift staffed only by no-shows is a gap', function () {
        ($this->assign)(($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00'), ShiftAssignment::STATUS_NO_SHOW);

        $gaps = ($this->coverage)()->gaps();

        expect($gaps)->toHaveCount(1)
            ->and($gaps[0]['type'])->toBe(ShiftCoverage::GAP_UNSTAFFED);
    });
});

describe('gantt layout', function () {
    test('packs overlapping shifts into separate lanes', function () {
        ($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00');
        ($this->makeShift)('2026-06-27 19:00', '2026-06-27 21:00');
        ($this->makeShift)('2026-06-27 20:00', '2026-06-27 22:00');

        $rows = ($this->coverage)()->ganttRows();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['lanes'])->toHaveCount(2)
            ->and($rows[0]['lanes'][0])->toHaveCount(2)
            ->and($rows[0]['lanes'][1])->toHaveCount(1);
    });

    test('positions times as percentages of the event window', function () {
        ($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00');

        $coverage = ($this->coverage)();

        expect($coverage->offsetPercent(Carbon::parse('2026-06-28 06:00', 'UTC')))->toEqual(50.0)
            ->and($coverage->widthPercent(Carbon::parse('2026-06-27 18:00', 'UTC'), Carbon::parse('2026-06-28 00:00', 'UTC')))->toEqual(25.0)
            ->and($coverage->offsetPercent(Carbon::parse('2026-06-20 00:00', 'UTC')))->toEqual(0.0);
    });

    test('extends the window to cover shifts outside the event', function () {
        ($this->makeShift)('2026-06-27 16:00', '2026-06-27 18:00');

        expect(($this->coverage)()->windowStart->toDateTimeString())->toBe('2026-06-27 16:00:00');
    });
});

describe('assigning', function () {
    beforeEach(function () {
        Permission::create(['name' => 'manage-shifts']);
        $this->manager = User::factory()->create();
        $this->manager->givePermissionTo('manage-shifts');
    });

    test('shift managers can click an open shift to assign someone', function () {
        $shift = ($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00');

        $this->actingAs($this->manager);

        Livewire::test(ShiftDashboard::class)
            ->assertSeeHtml('wire:click="openAssignModal('.$shift->id.')"')
            ->call('openAssignModal', $shift->id)
            ->assertSet('showAssignModal', true)
            ->set('assignUserId', $this->user->id)
            ->call('assignUser')
            ->assertHasNoErrors()
            ->assertSet('showAssignModal', false)
            ->assertSee('W1ABC');

        expect(ShiftAssignment::where('shift_id', $shift->id)->where('user_id', $this->user->id)->exists())->toBeTrue();
    });

    test('a user removed from a shift can be assigned to it again', function () {
        $shift = ($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00', capacity: 2);
        $removed = ($this->assign)($shift, ShiftAssignment::STATUS_CHECKED_IN);
        $removed->delete();

        $this->actingAs($this->manager);

        Livewire::test(ShiftDashboard::class)
            ->call('openAssignModal', $shift->id)
            ->set('assignUserId', $removed->user_id)
            ->call('assignUser')
            ->assertHasNoErrors()
            ->assertDispatched('toast', title: 'Success');

        $restored = ShiftAssignment::where('shift_id', $shift->id)->where('user_id', $removed->user_id)->sole();

        expect($restored->id)->toBe($removed->id)
            ->and($restored->status)->toBe(ShiftAssignment::STATUS_SCHEDULED)
            ->and($restored->signup_type)->toBe(ShiftAssignment::SIGNUP_TYPE_ASSIGNED)
            ->and($restored->checked_in_at)->toBeNull();
    });

    test('a no-show frees the slot for someone else', function () {
        $shift = ($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00');
        ($this->assign)($shift, ShiftAssignment::STATUS_NO_SHOW);

        $this->actingAs($this->manager);

        Livewire::test(ShiftDashboard::class)
            ->assertSeeHtml('wire:click="openAssignModal('.$shift->id.')"')
            ->call('openAssignModal', $shift->id)
            ->set('assignUserId', $this->user->id)
            ->call('assignUser')
            ->assertDispatched('toast', title: 'Success');

        expect(ShiftAssignment::where('shift_id', $shift->id)->where('user_id', $this->user->id)->exists())->toBeTrue();
    });

    test('gap list entries open the assign modal for their shift', function () {
        $shift = ($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00');

        $this->actingAs($this->manager);

        Livewire::test(ShiftDashboard::class)
            ->assertSeeHtmlInOrder(['data-testid="gaps-card"', 'aria-label="Assign a volunteer:', 'wire:click="openAssignModal('.$shift->id.')"']);
    });

    test('full shifts are not clickable', function () {
        $shift = ($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00');
        ($this->assign)($shift);

        $this->actingAs($this->manager);

        Livewire::test(ShiftDashboard::class)
            ->assertDontSeeHtml('openAssignModal('.$shift->id.')');
    });

    test('users without manage-shifts cannot assign from the dashboard', function () {
        $shift = ($this->makeShift)('2026-06-27 18:00', '2026-06-27 20:00');

        $this->actingAs($this->user);

        Livewire::test(ShiftDashboard::class)
            ->assertDontSeeHtml('openAssignModal(')
            ->assertDontSee('Assign Volunteer to Shift')
            ->call('openAssignModal', $shift->id)
            ->assertForbidden();
    });
});
