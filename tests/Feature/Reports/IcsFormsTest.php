<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\Credential;
use App\Livewire\Messages\MessageTrafficIndex;
use App\Livewire\Reports\ReportsIndex;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Message;
use App\Models\Net;
use App\Models\NetLogEntry;
use App\Models\OperationalPeriod;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\Station;
use App\Models\User;
use App\Models\UserCredential;
use App\Services\IcsFormBuilder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Permission::firstOrCreate(['name' => 'view-reports']);
    Permission::firstOrCreate(['name' => 'log-contacts']);

    $this->user = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'call_sign' => 'W1PAT']);
    $this->user->givePermissionTo(['view-reports', 'log-contacts']);

    $event = Event::factory()->publicService()->create([
        'name' => 'City Marathon',
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(6),
    ]);
    $this->config = EventConfiguration::factory()->publicService()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'club_name' => 'Anytown ARES',
        'talk_in_frequency' => '146.520 MHz simplex',
    ]);
    $this->event = $event;
});

/**
 * Every field name a payload uses must exist in the FEMA PDF, or the browser
 * silently skips it.
 *
 * @param  array{pages: list<array<string, string>>}  $form
 */
function expectFieldsExistInFemaPdf(array $form, string $pdf): void
{
    $known = json_decode(file_get_contents(base_path('tests/fixtures/ics-form-fields.json')), true)[$pdf];

    foreach ($form['pages'] as $page) {
        expect(array_diff(array_keys($page), $known))->toBe([]);
    }
}

test('the blank FEMA forms ship with the app', function (string $form) {
    expect(public_path("forms/ics/{$form}.pdf"))->toBeFile()
        ->and(file_get_contents(public_path("forms/ics/{$form}.pdf"), length: 5))->toBe('%PDF-');
})->with(['ics-205', 'ics-205a', 'ics-211', 'ics-213']);

test('the ICS-205 lists the talk-in frequency and each station channel', function () {
    Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'Aid Station 7',
        'tactical_callsign' => 'Aid 7',
        'rx_frequency' => '146.940',
        'tx_frequency' => '146.340',
        'tone' => '100.0',
        'comms_mode' => 'FM',
        'location' => 'Mile 12',
    ]);
    Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'Finish Line',
        'rx_frequency' => '441.000',
        'comms_mode' => 'DMR',
    ]);
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Spare Radio']);

    $form = $this->actingAs($this->user)->getJson(route('reports.ics-205'))->assertOk()->json();
    $page = $form['pages'][0];

    expect($form['template'])->toEndWith('/forms/ics/ics-205.pdf')
        ->and($form['filename'])->toBe('ics-205-city-marathon.pdf')
        ->and($form['pages'])->toHaveCount(1)
        ->and($page['1 Incident Name_8'])->toBe('City Marathon')
        ->and($page['6 Prepared by Communications Unit Leader Name'])->toBe('Pat Lee, W1PAT')
        ->and($page['FunctionRow1'])->toBe('Talk-in')
        ->and($page['RX Freq N or WRow1'])->toBe('146.520')
        ->and($page['AssignmentRow2'])->toBe('Aid 7')
        ->and($page['RX Freq N or WRow2'])->toBe('146.940')
        ->and($page['TX Freq N or WRow2'])->toBe('146.340')
        ->and($page['RX ToneNACRow2'])->toBe('100.0')
        ->and($page['Mode A D or MRow2'])->toBe('A')
        ->and($page['RemarksRow2'])->toBe('FM, Mile 12')
        ->and($page['RemarksRow3'])->toBe('DMR')
        ->and($page['Channel NameTrunked Radio System TalkgroupRow3'])->toBe('Finish Line')
        ->and($page['TX Freq N or WRow3'])->toBe('441.000')
        ->and($page['Mode A D or MRow3'])->toBe('D')
        ->and($page)->not->toHaveKey('Ch Row4');

    expectFieldsExistInFemaPdf($form, 'ics-205');
});

test('channels past the eighth row go on another page', function () {
    $this->config->update(['talk_in_frequency' => null]);
    foreach (range(1, IcsFormBuilder::ROWS_PER_PAGE + 2) as $number) {
        Station::factory()->create([
            'event_configuration_id' => $this->config->id,
            'name' => sprintf('Aid %02d', $number),
            'rx_frequency' => '146.520',
        ]);
    }

    $form = app(IcsFormBuilder::class)->ics205($this->config->fresh(), $this->user);

    expect($form['pages'])->toHaveCount(2)
        ->and($form['pages'][1]['Ch Row1'])->toBe('9')
        ->and($form['pages'][1]['Channel NameTrunked Radio System TalkgroupRow2'])->toBe('Aid 10')
        ->and($form['pages'][1]['1 Incident Name_8'])->toBe('City Marathon');
});

test('the ICS-211 lists shift check-ins in order', function () {
    $role = ShiftRole::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Net Control Operator', 'tactical_callsign' => 'Net Control']);
    $shift = Shift::factory()->create(['event_configuration_id' => $this->config->id, 'shift_role_id' => $role->id]);
    $sam = User::factory()->create(['first_name' => 'Sam', 'last_name' => 'Ray', 'call_sign' => 'K1SAM', 'phone' => '555-0142']);
    UserCredential::factory()->create(['user_id' => $sam->id, 'credential' => Credential::Ares, 'level' => 'Level 2']);
    UserCredential::factory()->create(['user_id' => $sam->id, 'credential' => Credential::Is100]);
    UserCredential::factory()->expired()->create(['user_id' => $sam->id, 'credential' => Credential::Coml]);
    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $sam->id,
        'status' => ShiftAssignment::STATUS_CHECKED_IN,
        'checked_in_at' => now()->subHour(),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => User::factory()->create(['call_sign' => 'K1NOT'])->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
        'checked_in_at' => null,
    ]);

    $form = $this->actingAs($this->user)->getJson(route('reports.ics-211'))->assertOk()->json();
    $page = $form['pages'][0];

    expect($page['1 Incident Name'])->toBe('City Marathon')
        ->and($page['R1 Resource Name or ID 1'])->toBe('K1SAM')
        ->and($page["R1 Leader's Name 1"])->toBe('Sam Ray')
        ->and($page['R1 Incident Assignment 1'])->toBe('Net Control')
        ->and($page['R1 Agency 1'])->toBe('Anytown ARES')
        ->and($page['R1 Other Qualifications 1'])->toBe('IS-100, ARES L2')
        ->and($page['R1 Incident Contact 1'])->toBe('555-0142')
        ->and($page['R1 Date/Time Check In 1'])->not->toBe('')
        ->and($page)->not->toHaveKey('R2 Resource Name or ID 2');

    expectFieldsExistInFemaPdf($form, 'ics-211');
});

test('the ICS-205A lists everyone with a shift, by last name, with contact and credentials', function () {
    $netControl = ShiftRole::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Net Control Operator', 'tactical_callsign' => 'Net Control']);
    $logger = ShiftRole::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Logger', 'tactical_callsign' => null]);
    $morning = Shift::factory()->create(['event_configuration_id' => $this->config->id, 'shift_role_id' => $netControl->id]);
    $evening = Shift::factory()->create(['event_configuration_id' => $this->config->id, 'shift_role_id' => $netControl->id]);
    $logging = Shift::factory()->create(['event_configuration_id' => $this->config->id, 'shift_role_id' => $logger->id]);

    $sam = User::factory()->create(['first_name' => 'Sam', 'last_name' => 'Young', 'call_sign' => 'K1SAM', 'phone' => '555-0142', 'email' => 'sam@example.com']);
    UserCredential::factory()->create(['user_id' => $sam->id, 'credential' => Credential::Auxcomm]);
    $ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Abbott', 'call_sign' => 'W1ANN', 'phone' => null, 'email' => 'ann@example.com']);
    $gone = User::factory()->create(['last_name' => 'Missing', 'call_sign' => 'K1NOP']);

    foreach ([[$morning, $sam], [$evening, $sam], [$logging, $ann]] as [$shift, $user]) {
        ShiftAssignment::factory()->create(['shift_id' => $shift->id, 'user_id' => $user->id, 'status' => ShiftAssignment::STATUS_SCHEDULED]);
    }
    ShiftAssignment::factory()->create(['shift_id' => $logging->id, 'user_id' => $gone->id, 'status' => ShiftAssignment::STATUS_NO_SHOW]);

    $form = $this->actingAs($this->user)->getJson(route('reports.ics-205a'))->assertOk()->json();
    $page = $form['pages'][0];

    expect($form['template'])->toEndWith('/forms/ics/ics-205a.pdf')
        ->and($form['filename'])->toBe('ics-205a-city-marathon.pdf')
        ->and($page['1 Incident Name_9'])->toBe('City Marathon')
        ->and($page['4 Prepared by Name'])->toBe('Pat Lee, W1PAT')
        ->and($page['Name AlphabetizedRow1'])->toBe('Abbott, Ann, W1ANN')
        ->and($page['Incident Assigned PositionRow1'])->toBe('Logger')
        ->and($page['Methods of Contact phone pager cell etcRow1'])->toBe('ann@example.com')
        ->and($page['Name AlphabetizedRow2'])->toBe('Young, Sam, K1SAM')
        ->and($page['Incident Assigned PositionRow2'])->toBe('Net Control Operator / Net Control')
        ->and($page['Methods of Contact phone pager cell etcRow2'])->toBe('555-0142, sam@example.com; AUXCOMM')
        ->and($page)->not->toHaveKey('Name AlphabetizedRow3');

    expectFieldsExistInFemaPdf($form, 'ics-205a');
});

test('the ICS-205A fits 34 people on a page', function () {
    $role = ShiftRole::factory()->create(['event_configuration_id' => $this->config->id]);
    $shift = Shift::factory()->create(['event_configuration_id' => $this->config->id, 'shift_role_id' => $role->id]);
    User::factory()->count(IcsFormBuilder::COMMS_LIST_ROWS_PER_PAGE + 1)->create()
        ->each(fn (User $user) => ShiftAssignment::factory()->create(['shift_id' => $shift->id, 'user_id' => $user->id]));

    $form = app(IcsFormBuilder::class)->ics205a($this->config->fresh(), $this->user);

    expect($form['pages'])->toHaveCount(2)
        ->and($form['pages'][0])->toHaveKey('Name AlphabetizedRow34')
        ->and($form['pages'][1])->toHaveKey('Name AlphabetizedRow1')
        ->and($form['pages'][1])->not->toHaveKey('Name AlphabetizedRow2');
});

test('the ICS-213 fills a message into the FEMA form', function () {
    $message = Message::factory()->ics213()->create([
        'event_configuration_id' => $this->config->id,
        'addressee_name' => 'Jo Park',
        'ics_to_position' => 'Medical Unit Leader',
        'signature' => 'Pat Lee',
        'ics_from_position' => 'Net Control',
        'ics_subject' => 'Water resupply',
        'message_text' => 'Aid 7 needs 20 cases of water by 10:00.',
    ]);

    $form = $this->actingAs($this->user)
        ->getJson(route('events.messages.ics-213', [$this->event, $message]))
        ->assertOk()
        ->json();
    $page = $form['pages'][0];

    expect($page['2 To Name and Position'])->toBe('Jo Park, Medical Unit Leader')
        ->and($page['3 From Name and Position'])->toBe('Pat Lee, Net Control')
        ->and($page['4 Subject'])->toBe('Water resupply')
        ->and($page['7 Message'])->toBe('Aid 7 needs 20 cases of water by 10:00.');

    expectFieldsExistInFemaPdf($form, 'ics-213');
});

test('the ICS-213 endpoint refuses radiograms and other events\' messages', function () {
    $radiogram = Message::factory()->create(['event_configuration_id' => $this->config->id]);
    $elsewhere = Message::factory()->ics213()->create();

    $this->actingAs($this->user)->getJson(route('events.messages.ics-213', [$this->event, $radiogram]))->assertNotFound();
    $this->actingAs($this->user)->getJson(route('events.messages.ics-213', [$this->event, $elsewhere]))->assertNotFound();
});

test('the ICS-309 prints the net log in time order', function () {
    NetLogEntry::factory()->create([
        'event_configuration_id' => $this->config->id,
        'logged_at' => now()->subMinutes(5),
        'from_station' => 'Aid 7',
        'from_callsign' => 'W1ABC',
        'message' => 'Second call',
    ]);
    NetLogEntry::factory()->emergency()->create([
        'event_configuration_id' => $this->config->id,
        'logged_at' => now()->subMinutes(30),
        'message' => 'First call',
    ]);

    $this->actingAs($this->user)->get(route('reports.ics-309'))
        ->assertOk()
        ->assertSee('COMMUNICATIONS LOG (ICS 309)')
        ->assertSee('Aid 7 (W1ABC)')
        ->assertSee('[EMERGENCY]')
        ->assertSeeInOrder(['First call', 'Second call']);
});

test('the ICS-309 for one net lists only that net and names it', function () {
    $local = Net::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Local 2m Net', 'frequency' => '146.520 MHz', 'net_control_station' => 'NCS']);
    $hf = Net::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Regional HF Net']);
    NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $local->id, 'message' => 'ON TWO METERS']);
    NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $hf->id, 'message' => 'ON EIGHTY METERS']);

    $this->actingAs($this->user)->get(route('reports.ics-309', ['net' => $local->id]))
        ->assertOk()
        ->assertSee('Local 2m Net · 146.520 MHz · NCS')
        ->assertSee('ON TWO METERS')
        ->assertDontSee('ON EIGHTY METERS');

    $this->actingAs($this->user)->get(route('reports.ics-309'))
        ->assertSee('Regional HF Net:')
        ->assertSee('ON EIGHTY METERS');

    $this->actingAs($this->user)->get(route('reports.ics-309', ['net' => Net::factory()->create()->id]))
        ->assertNotFound();
});

test('ICS forms need the view-reports permission', function (string $route) {
    $this->actingAs(User::factory()->create())->get(route($route))->assertForbidden();
})->with(['reports.ics-205', 'reports.ics-205a', 'reports.ics-211', 'reports.ics-309']);

test('the reports page offers the ICS forms', function () {
    Livewire::actingAs($this->user)->test(ReportsIndex::class)
        ->assertSeeHtml('data-testid="ics-205"')
        ->assertSeeHtml('data-testid="ics-211"')
        ->assertSee(route('reports.ics-309'))
        ->assertSee("icsPdf('".route('reports.ics-205')."')", false)
        ->assertSeeHtml('x-on:click="openPdf()"');
});

test('ICS-213 messages offer the FEMA PDF', function () {
    Setting::set('enable_ics213', true);
    $message = Message::factory()->ics213()->create(['event_configuration_id' => $this->config->id]);
    $radiogram = Message::factory()->create(['event_configuration_id' => $this->config->id]);

    Livewire::actingAs($this->user)->test(MessageTrafficIndex::class, ['event' => $this->event])
        ->assertSee(route('events.messages.ics-213', [$this->event, $message]), false)
        ->assertSeeHtml('x-on:click="openPdf()"')
        ->assertDontSee(route('events.messages.ics-213', [$this->event, $radiogram]), false);
});

describe('operational periods', function () {
    beforeEach(function () {
        $this->earlier = OperationalPeriod::factory()->create([
            'event_configuration_id' => $this->config->id,
            'name' => 'Early Shift',
            'start_time' => now()->subHours(2)->startOfMinute(),
            'end_time' => now()->subHour()->startOfMinute(),
        ]);
        $this->current = OperationalPeriod::factory()->create([
            'event_configuration_id' => $this->config->id,
            'name' => 'Late Shift',
            'start_time' => now()->subHour()->startOfMinute(),
            'end_time' => now()->addHours(6)->startOfMinute(),
        ]);
    });

    test('form headers show the period under way', function () {
        $form = $this->actingAs($this->user)->getJson(route('reports.ics-205'))->assertOk()->json();

        expect($form['pages'][0]['Time From'])->toBe(toLocalTime($this->current->start_time)->format('H:i'))
            ->and($form['pages'][0]['Time To'])->toBe(toLocalTime($this->current->end_time)->format('H:i'));
    });

    test('a chosen period fills the header and limits the people to its shifts', function () {
        $role = ShiftRole::factory()->create(['event_configuration_id' => $this->config->id]);
        $earlyShift = Shift::factory()->create(['event_configuration_id' => $this->config->id, 'shift_role_id' => $role->id, 'start_time' => $this->earlier->start_time, 'end_time' => $this->earlier->end_time]);
        $lateShift = Shift::factory()->create(['event_configuration_id' => $this->config->id, 'shift_role_id' => $role->id, 'start_time' => $this->current->start_time, 'end_time' => $this->current->end_time]);
        foreach (['K1EAR' => $earlyShift, 'K1LAT' => $lateShift] as $call => $shift) {
            ShiftAssignment::factory()->create([
                'shift_id' => $shift->id,
                'user_id' => User::factory()->create(['call_sign' => $call])->id,
                'status' => ShiftAssignment::STATUS_CHECKED_IN,
                'checked_in_at' => $shift->start_time,
            ]);
        }

        $ics211 = $this->actingAs($this->user)->getJson(route('reports.ics-211', ['period' => $this->earlier->id]))->json();
        $ics205a = $this->actingAs($this->user)->getJson(route('reports.ics-205a', ['period' => $this->earlier->id]))->json();

        expect($ics211['pages'][0]['Start Time'])->toBe(toLocalTime($this->earlier->start_time)->format('H:i'))
            ->and($ics211['pages'][0]['R1 Resource Name or ID 1'])->toBe('K1EAR')
            ->and($ics211['pages'][0])->not->toHaveKey('R2 Resource Name or ID 2')
            ->and($ics205a['pages'][0]['Name AlphabetizedRow1'])->toContain('K1EAR')
            ->and($ics205a['pages'][0])->not->toHaveKey('Name AlphabetizedRow2');
    });

    test('the ICS-309 lists only the period\'s net log entries', function () {
        NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'logged_at' => now()->subMinutes(90), 'message' => 'EARLY TRAFFIC']);
        NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'logged_at' => now()->subMinutes(10), 'message' => 'LATE TRAFFIC']);

        $this->actingAs($this->user)->get(route('reports.ics-309'))
            ->assertSee('Late Shift:')
            ->assertSee('LATE TRAFFIC')
            ->assertDontSee('EARLY TRAFFIC');

        $this->actingAs($this->user)->get(route('reports.ics-309', ['period' => $this->earlier->id]))
            ->assertSee('Early Shift:')
            ->assertSee('EARLY TRAFFIC')
            ->assertDontSee('LATE TRAFFIC');
    });

    test('the reports page picks the current period and links the forms to it', function () {
        Livewire::actingAs($this->user)->test(ReportsIndex::class)
            ->assertSet('icsPeriod', $this->current->id)
            ->assertSeeHtml('data-testid="ics-period"')
            ->assertSee(route('reports.ics-309', ['period' => $this->current->id]))
            ->set('icsPeriod', $this->earlier->id)
            ->assertSee("icsPdf('".route('reports.ics-205', ['period' => $this->earlier->id])."')", false);
    });
});

test('the reports page binds Livewire to its root element so the period picker updates', function () {
    $html = Livewire::actingAs($this->user)->test(ReportsIndex::class)->html();

    expect(trim($html))->toMatch('/^<div[^>]*wire:id=/');
});

test('without periods the reports page has no period picker', function () {
    Livewire::actingAs($this->user)->test(ReportsIndex::class)
        ->assertSet('icsPeriod', null)
        ->assertDontSeeHtml('data-testid="ics-period"');
});
