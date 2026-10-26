<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\ChecklistAnswerType;
use App\Livewire\Checklists\ChecklistShow;
use App\Models\AuditLog;
use App\Models\BonusType;
use App\Models\Checklist;
use App\Models\ChecklistAnswer;
use App\Models\ChecklistItem;
use App\Models\ChecklistRun;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\OperatingClass;
use App\Models\OperationalPeriod;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\Station;
use App\Models\User;
use App\Services\ChecklistService;
use Livewire\Livewire;

beforeEach(function () {
    $this->event = Event::factory()->create([
        'name' => 'Field Day 2026',
        'rules_version' => '2025',
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);

    $this->safetyRole = ShiftRole::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Safety Officer',
    ]);

    $this->regularUser = User::factory()->create(['first_name' => 'Regular', 'last_name' => 'User']);
    $this->safetyOfficer = User::factory()->create(['first_name' => 'Safety', 'last_name' => 'Officer']);

    ShiftAssignment::factory()->create([
        'shift_id' => Shift::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'shift_role_id' => $this->safetyRole->id])->id,
        'user_id' => $this->safetyOfficer->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $this->checklist = Checklist::factory()->safetyOfficer()->create(['event_configuration_id' => $this->eventConfig->id]);
    $this->checklist->shiftRoles()->attach($this->safetyRole);
    $this->item = ChecklistItem::factory()->required()->default()->create([
        'checklist_id' => $this->checklist->id,
        'label' => 'Fire extinguisher on hand',
        'help_text' => 'Place a minimum 5-lb ABC-rated fire extinguisher nearby.',
    ]);

    $this->safetyBonus = fn () => BonusType::factory()->create([
        'event_type_id' => $this->event->event_type_id,
        'rules_version' => '2025',
        'code' => 'safety_officer',
        'base_points' => 100,
        'eligible_classes' => ['A'],
    ]);
});

describe('viewing', function () {
    test('any signed-in user sees the items and their help text', function () {
        $this->actingAs($this->regularUser);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->checklist])
            ->assertOk()
            ->assertSee('Safety Officer')
            ->assertSee('Fire extinguisher on hand')
            ->assertSee('Place a minimum 5-lb ABC-rated fire extinguisher nearby.')
            ->assertSee('0 of 1 answered')
            ->assertSee('Only volunteers assigned to a')
            ->assertDontSeeHtml('wire:click="answer(');
    });

    test('guests are sent to sign in', function () {
        $this->get(route('checklists.show', $this->checklist))->assertRedirect(route('login'));
    });

    test('opening a checklist does not start a run', function () {
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->checklist]);

        expect(ChecklistRun::count())->toBe(0);
    });

    test('lists users marked CPR/AED trained beside the CPR item only', function () {
        ChecklistItem::factory()->create(['checklist_id' => $this->checklist->id, 'label' => 'First Aid - CPR - AED versed participant on site']);
        User::factory()->create(['call_sign' => 'K1CPR', 'first_name' => 'Pat', 'is_cpr_aed_trained' => true]);

        $this->actingAs($this->regularUser);

        $html = Livewire::test(ChecklistShow::class, ['checklist' => $this->checklist])->html();

        expect(substr_count($html, 'Volunteers marked as CPR/AED trained'))->toBe(1)
            ->and($html)->toContain('K1CPR (Pat)');
    });
});

describe('answering', function () {
    test('the safety officer checks an item off, with who and when', function () {
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->checklist])
            ->assertSeeHtmlInOrder([
                '<label class="-m-2.5 p-2.5 cursor-pointer shrink-0">',
                'aria-label="Mark complete: Fire extinguisher on hand"',
            ])
            ->call('answer', $this->item->id, true)
            ->assertDispatched('autosaved')
            ->assertSee('Completed by Safety Officer')
            ->assertSee('1 of 1 answered');

        $answer = ChecklistAnswer::sole();
        expect($answer->value)->toBe('1')
            ->and($answer->answered_by_user_id)->toBe($this->safetyOfficer->id)
            ->and(ChecklistRun::sole()->completed_at)->not->toBeNull();
    });

    test('unticking clears the answer and the completion', function () {
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->checklist])
            ->call('answer', $this->item->id, true)
            ->call('answer', $this->item->id, null);

        expect(ChecklistAnswer::sole()->value)->toBeNull()
            ->and(ChecklistRun::sole()->completed_at)->toBeNull();
    });

    test('notes save on their own', function () {
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->checklist])
            ->call('saveNotes', $this->item->id, 'Two by the generator')
            ->assertDispatched('autosaved');

        expect(ChecklistAnswer::sole())->notes->toBe('Two by the generator')->value->toBeNull();
    });

    test('a volunteer outside the safety role cannot answer or add notes', function () {
        $this->actingAs($this->regularUser);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->checklist])
            ->call('answer', $this->item->id, true)
            ->call('saveNotes', $this->item->id, 'Sneaky');

        expect(ChecklistAnswer::count())->toBe(0);
    });

    test('an item from another checklist is ignored', function () {
        $other = ChecklistItem::factory()->create();
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->checklist])
            ->call('answer', $other->id, true);

        expect(ChecklistAnswer::count())->toBe(0);
    });

    test('answering is written to the audit log', function () {
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->checklist])
            ->call('answer', $this->item->id, true);

        $log = AuditLog::where('action', 'checklist.item.answered')->sole();
        expect($log->new_values)->toMatchArray(['label' => 'Fire extinguisher on hand', 'value' => 'Done', 'checklist' => 'Safety Officer']);
    });

    test('each answer type stores and shows its answer', function (ChecklistAnswerType $type, mixed $input, ?string $stored) {
        $checklist = Checklist::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
        $item = ChecklistItem::factory()->answerType($type)->create(['checklist_id' => $checklist->id]);
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $checklist])
            ->call('answer', $item->id, $input);

        expect(ChecklistAnswer::sole()->value)->toBe($stored);
    })->with([
        'yes' => [ChecklistAnswerType::YesNoNa, 'na', 'na'],
        'bad choice' => [ChecklistAnswerType::YesNoNa, 'maybe', null],
        'text' => [ChecklistAnswerType::Text, '  Mile 12  ', 'Mile 12'],
        'number' => [ChecklistAnswerType::Number, '12.60', '12.6'],
        'not a number' => [ChecklistAnswerType::Number, 'twelve', null],
    ]);

    test('the other answer types get touch-friendly controls', function () {
        $checklist = Checklist::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
        ChecklistItem::factory()->answerType(ChecklistAnswerType::YesNoNa)->create(['checklist_id' => $checklist->id, 'label' => 'Backup radio']);
        ChecklistItem::factory()->answerType(ChecklistAnswerType::Number)->create(['checklist_id' => $checklist->id, 'label' => 'Battery voltage']);
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $checklist])
            ->assertSeeHtml('aria-label="Answer: Backup radio"')
            ->assertSeeHtml('touch-manipulation')
            ->assertSeeHtml('inputmode="decimal"');
    });

    test('saving one answer only marks the tapped button busy', function () {
        $checklist = Checklist::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
        ChecklistItem::factory()->answerType(ChecklistAnswerType::YesNoNa)->create(['checklist_id' => $checklist->id]);
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $checklist])
            ->assertSeeHtml('data-loading:pointer-events-none')
            ->assertDontSeeHtml('wire:loading.attr="disabled"');
    });
});

describe('the bonus', function () {
    test('finishing the checklist claims the bonus and unticking removes it', function () {
        ($this->safetyBonus)();
        $this->actingAs($this->safetyOfficer);

        $component = Livewire::test(ChecklistShow::class, ['checklist' => $this->checklist])
            ->call('answer', $this->item->id, true)
            ->assertDispatched('bonus-claimed');

        expect(EventBonus::where('event_configuration_id', $this->eventConfig->id)->sole()->calculated_points)->toBe(100);

        $component->call('answer', $this->item->id, null)
            ->assertDispatched('toast', fn ($name, $params) => $params['title'] === 'Bonus removed');

        expect(EventBonus::count())->toBe(0);
    });

    test('a partly done checklist earns nothing', function () {
        ($this->safetyBonus)();
        ChecklistItem::factory()->create(['checklist_id' => $this->checklist->id]);
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->checklist])
            ->call('answer', $this->item->id, true)
            ->assertNotDispatched('bonus-claimed');

        expect(EventBonus::count())->toBe(0);
    });

    test('a class F event earns nothing from the safety officer bonus', function () {
        ($this->safetyBonus)();
        $this->eventConfig->update(['operating_class_id' => OperatingClass::where('event_type_id', $this->event->event_type_id)->where('code', 'F')->value('id')]);
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->checklist])
            ->call('answer', $this->item->id, true)
            ->assertNotDispatched('bonus-claimed');

        expect(EventBonus::count())->toBe(0);
    });
});

describe('per-station checklists', function () {
    beforeEach(function () {
        $this->stationChecklist = Checklist::factory()->perStation()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Station Setup']);
        $this->stationItem = ChecklistItem::factory()->required()->create(['checklist_id' => $this->stationChecklist->id, 'label' => 'Antenna up']);
        $this->aid7 = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Aid 7', 'location' => 'Mile 12']);
        $this->aid8 = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Aid 8']);
    });

    test('list every station with how far each has got', function () {
        ChecklistRun::factory()->create([
            'checklist_id' => $this->stationChecklist->id,
            'subject_key' => ChecklistRun::stationKey($this->aid7->id),
            'station_id' => $this->aid7->id,
            'completed_at' => now(),
        ]);
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->stationChecklist])
            ->assertSeeInOrder(['Aid 7', 'Mile 12', 'Done', 'Aid 8', 'Not started'])
            ->assertDontSee('Antenna up');
    });

    test('each station gets its own run', function () {
        $this->actingAs($this->safetyOfficer);

        Livewire::withQueryParams(['station' => $this->aid7->id])
            ->test(ChecklistShow::class, ['checklist' => $this->stationChecklist])
            ->assertSeeText('Station Setup: Aid 7')
            ->call('answer', $this->stationItem->id, true);

        Livewire::withQueryParams(['station' => $this->aid8->id])
            ->test(ChecklistShow::class, ['checklist' => $this->stationChecklist])
            ->assertSee('0 of 1 answered');

        expect(ChecklistRun::sole())
            ->station_id->toBe($this->aid7->id)
            ->completed_at->not->toBeNull();
    });

    test('a station from another event is not offered', function () {
        $elsewhere = Station::factory()->create();
        $this->actingAs($this->safetyOfficer);

        Livewire::withQueryParams(['station' => $elsewhere->id])
            ->test(ChecklistShow::class, ['checklist' => $this->stationChecklist])
            ->assertSee('Aid 8')
            ->call('answer', $this->stationItem->id, true);

        expect(ChecklistRun::count())->toBe(0);
    });
});

describe('per-person checklists', function () {
    beforeEach(function () {
        $this->goKit = Checklist::factory()->perPerson()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Go-Kit']);
        $this->goKitItem = ChecklistItem::factory()->create(['checklist_id' => $this->goKit->id, 'label' => 'Spare batteries']);
    });

    test('a volunteer goes straight to their own checklist and can fill it in', function () {
        $this->actingAs($this->regularUser);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->goKit])
            ->assertSet('person', $this->regularUser->id)
            ->call('answer', $this->goKitItem->id, true);

        expect(ChecklistRun::sole()->user_id)->toBe($this->regularUser->id);
    });

    test('a volunteer asking for someone else\'s gets their own', function () {
        $this->actingAs($this->regularUser);

        Livewire::withQueryParams(['person' => $this->safetyOfficer->id])
            ->test(ChecklistShow::class, ['checklist' => $this->goKit])
            ->assertSet('person', $this->regularUser->id)
            ->assertSeeText('Go-Kit: '.$this->regularUser->call_sign);
    });
});

describe('per-period checklists', function () {
    beforeEach(function () {
        $this->radioCheck = Checklist::factory()->perPeriod()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Radio Check']);
        $this->radioItem = ChecklistItem::factory()->required()->create(['checklist_id' => $this->radioCheck->id, 'label' => 'Net control heard']);
    });

    test('without periods it runs once for the whole event', function () {
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->radioCheck])
            ->assertSee('Net control heard')
            ->call('answer', $this->radioItem->id, true);

        expect(ChecklistRun::sole())
            ->subject_key->toBe(ChecklistRun::EVENT_SUBJECT)
            ->operational_period_id->toBeNull()
            ->and(app(ChecklistService::class)->progress($this->radioCheck))->toBe(['done' => 1, 'total' => 1]);
    });

    test('with periods each period gets its own run', function () {
        $am = OperationalPeriod::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Day 1 AM', 'start_time' => now()->subHours(8), 'end_time' => now()]);
        $pm = OperationalPeriod::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Day 1 PM', 'start_time' => now(), 'end_time' => now()->addHours(8)]);
        OperationalPeriod::factory()->create(['name' => 'Someone else\'s period']);
        $this->actingAs($this->safetyOfficer);

        Livewire::test(ChecklistShow::class, ['checklist' => $this->radioCheck])
            ->assertSeeInOrder(['Day 1 AM', 'Not started', 'Day 1 PM', 'Not started'])
            ->assertDontSee('Someone else')
            ->assertDontSee('Net control heard');

        Livewire::withQueryParams(['period' => $pm->id])
            ->test(ChecklistShow::class, ['checklist' => $this->radioCheck])
            ->assertSeeText('Radio Check: Day 1 PM')
            ->call('answer', $this->radioItem->id, true);

        expect(ChecklistRun::sole())
            ->subject_key->toBe(ChecklistRun::periodKey($pm->id))
            ->operational_period_id->toBe($pm->id)
            ->and(app(ChecklistService::class)->progress($this->radioCheck))->toBe(['done' => 1, 'total' => 2]);

        Livewire::withQueryParams(['period' => $am->id])
            ->test(ChecklistShow::class, ['checklist' => $this->radioCheck])
            ->assertSee('0 of 1 answered');
    });

    test('a whole-event run stops counting once the event has periods', function () {
        ChecklistRun::factory()->create(['checklist_id' => $this->radioCheck->id, 'completed_at' => now()]);
        OperationalPeriod::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

        expect(app(ChecklistService::class)->progress($this->radioCheck))->toBe(['done' => 0, 'total' => 1]);
    });

    test('deleting a period deletes its runs', function () {
        $period = OperationalPeriod::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
        ChecklistRun::factory()->create([
            'checklist_id' => $this->radioCheck->id,
            'subject_key' => ChecklistRun::periodKey($period->id),
            'operational_period_id' => $period->id,
        ]);

        $period->delete();

        expect(ChecklistRun::count())->toBe(0);
    });
});
