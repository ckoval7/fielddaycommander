<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Checklists\ChecklistIndex;
use App\Livewire\Checklists\ChecklistShow;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ChecklistRun;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Station;
use App\Models\StationDevice;
use App\Models\User;
use App\Support\StationMode;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->event = Event::factory()->create([
        'rules_version' => '2025',
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);
    $this->user = User::factory()->create();
});

test('a class A event starts with the ARRL safety officer checklist', function () {
    $this->actingAs($this->user);

    Livewire::test(ChecklistIndex::class)
        ->assertOk()
        ->assertSee('Safety Officer')
        ->assertSee('Bonus')
        ->assertSee('Not done yet');

    expect(Checklist::sole()->template_key)->toBe('arrl_safety_officer');
});

test('an unscored event starts with the volunteer briefing', function () {
    $this->event->update(['start_time' => appNow()->subDays(3), 'end_time' => appNow()->subDays(2)]);
    $event = Event::factory()->publicService()->create(['start_time' => appNow()->subHours(2), 'end_time' => appNow()->addHours(6)]);
    $config = EventConfiguration::factory()->publicService()->create(['event_id' => $event->id]);
    Setting::set('active_event_id', $event->id);
    $this->actingAs($this->user);

    Livewire::test(ChecklistIndex::class)->assertSee('Volunteer Briefing');

    expect(Checklist::where('event_configuration_id', $config->id)->pluck('template_key')->all())->toBe(['briefing']);
});

test('shows how many stations and people are done', function () {
    $this->eventConfig->checklists()->delete();
    $stations = Station::factory()->count(3)->create(['event_configuration_id' => $this->eventConfig->id]);
    $setup = Checklist::factory()->perStation()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Station Setup']);
    ChecklistRun::factory()->create([
        'checklist_id' => $setup->id,
        'subject_key' => ChecklistRun::stationKey($stations[0]->id),
        'station_id' => $stations[0]->id,
        'completed_at' => now(),
    ]);

    $goKit = Checklist::factory()->perPerson()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Go-Kit']);
    ShiftAssignment::factory()->create([
        'shift_id' => Shift::factory()->create(['event_configuration_id' => $this->eventConfig->id])->id,
        'user_id' => $this->user->id,
    ]);
    ChecklistItem::factory()->count(2)->create(['checklist_id' => $goKit->id]);

    $this->actingAs($this->user);

    Livewire::test(ChecklistIndex::class)
        ->assertSee('1 of 3 stations done')
        ->assertSee('0 of 1 person done')
        ->assertSee('2 items');
});

test('the manage button only shows to schedule managers', function () {
    Permission::firstOrCreate(['name' => 'manage-shifts']);
    $this->actingAs($this->user);
    Livewire::test(ChecklistIndex::class)->assertDontSee('Manage Checklists');

    $this->user->givePermissionTo('manage-shifts');
    Livewire::test(ChecklistIndex::class)->assertSee('Manage Checklists');
});

test('guests are sent to sign in, and the old safety page redirects here', function () {
    Setting::set('setup_completed', 'true');

    $this->get(route('checklists.index'))->assertRedirect(route('login'));
    $this->get('/site-safety')->assertRedirect('/checklists');
    $this->get('/site-safety/manage')->assertRedirect('/checklists/manage');
});

describe('station mode', function () {
    beforeEach(function () {
        $this->station = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Aid 7']);
        $this->otherStation = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Aid 8']);
        $this->device = StationDevice::factory()->create(['station_id' => $this->station->id]);
        $this->setup = Checklist::factory()->perStation()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Station Setup']);
        $this->setupItem = ChecklistItem::factory()->create(['checklist_id' => $this->setup->id, 'label' => 'Antenna up']);
        $this->actingAs($this->user);
        session([StationMode::SESSION_KEY => $this->device->id]);
    });

    test('shows only the per-station checklists', function () {
        Checklist::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Volunteer Briefing']);

        Livewire::test(ChecklistIndex::class)
            ->assertSee('Station Setup')
            ->assertDontSee('Volunteer Briefing');
    });

    test('a walk-up operator works through the bound station\'s checklist', function () {
        Livewire::withQueryParams(['station' => $this->otherStation->id])
            ->test(ChecklistShow::class, ['checklist' => $this->setup])
            ->assertSet('station', $this->station->id)
            ->assertSeeText('Station Setup: Aid 7')
            ->call('answer', $this->setupItem->id, true);

        expect(ChecklistRun::sole()->station_id)->toBe($this->station->id);
    });

    test('event-wide checklists are closed in station mode', function () {
        $safety = Checklist::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

        Livewire::test(ChecklistShow::class, ['checklist' => $safety])->assertForbidden();
    });

    test('the checklist pages are on the station mode route list', function () {
        expect(StationMode::ALLOWED_ROUTES)->toContain('checklists.index', 'checklists.show');
    });
});
