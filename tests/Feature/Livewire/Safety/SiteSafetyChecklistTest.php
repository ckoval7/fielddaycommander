<?php

use App\Livewire\Safety\SiteSafetyChecklist;
use App\Models\BonusType;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\SafetyChecklistEntry;
use App\Models\SafetyChecklistItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->event = Event::factory()->create([
        'name' => 'Field Day 2026',
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);

    $this->safetyRole = ShiftRole::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Safety Officer',
        'icon' => 'o-shield-check',
        'color' => '#dc2626',
        'requires_confirmation' => true,
    ]);

    $this->safetyShift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->safetyRole->id,
    ]);

    $this->regularUser = User::factory()->create([
        'first_name' => 'Regular',
        'last_name' => 'User',
    ]);

    $this->safetyOfficer = User::factory()->create([
        'first_name' => 'Safety',
        'last_name' => 'Officer',
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $this->safetyShift->id,
        'user_id' => $this->safetyOfficer->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);
});

// =============================================================================
// Access
// =============================================================================

describe('access', function () {
    test('any authenticated user can view the checklist', function () {
        $this->actingAs($this->regularUser);

        Livewire::test(SiteSafetyChecklist::class)
            ->assertStatus(200)
            ->assertSee('Site Safety Checklist');
    });

    test('unauthenticated users are redirected', function () {
        $this->get(route('site-safety.index'))
            ->assertRedirect(route('login'));
    });
});

// =============================================================================
// Display
// =============================================================================

describe('display', function () {
    test('shows checklist items', function () {
        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Fire extinguisher on hand',
        ]);
        SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item->id,
        ]);

        $this->actingAs($this->regularUser);

        Livewire::test(SiteSafetyChecklist::class)
            ->assertSee('Fire extinguisher on hand');
    });

    test('shows completion summary', function () {
        $item1 = SafetyChecklistItem::factory()->safetyOfficer()->required()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Item One',
        ]);
        SafetyChecklistEntry::factory()->completed()->create([
            'safety_checklist_item_id' => $item1->id,
        ]);

        $item2 = SafetyChecklistItem::factory()->safetyOfficer()->required()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Item Two',
        ]);
        SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item2->id,
        ]);

        $this->actingAs($this->regularUser);

        Livewire::test(SiteSafetyChecklist::class)
            ->assertSee('1 of 2')
            ->assertSee('1 of 2 required');
    });

    test('seeds defaults if no items exist', function () {
        $this->actingAs($this->regularUser);

        Livewire::test(SiteSafetyChecklist::class)
            ->assertStatus(200);

        // EventConfig factory uses operating class '1A' (class A) which seeds SafetyOfficer items
        expect(SafetyChecklistItem::forEvent($this->eventConfig->id)->count())->toBeGreaterThan(0);
    });
});

// =============================================================================
// Editing
// =============================================================================

describe('editing', function () {
    test('safety officer can toggle item completion', function () {
        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Test toggle item',
        ]);
        $entry = SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item->id,
        ]);

        $this->actingAs($this->safetyOfficer);

        Livewire::test(SiteSafetyChecklist::class)
            ->call('toggleItem', $item->id);

        expect($entry->fresh()->is_completed)->toBeTrue();
    });

    test('safety officer can update notes', function () {
        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Test notes item',
        ]);
        $entry = SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item->id,
        ]);

        $this->actingAs($this->safetyOfficer);

        Livewire::test(SiteSafetyChecklist::class)
            ->call('updateNotes', $item->id, 'Extra fire extinguisher added');

        expect($entry->fresh()->notes)->toBe('Extra fire extinguisher added');
    });

    test('regular user cannot toggle items', function () {
        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Cannot toggle item',
        ]);
        $entry = SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item->id,
        ]);

        $this->actingAs($this->regularUser);

        Livewire::test(SiteSafetyChecklist::class)
            ->call('toggleItem', $item->id);

        expect($entry->fresh()->is_completed)->toBeFalse();
    });

    test('revokes bonus when unchecking breaks the gate', function () {
        $bonusType = BonusType::factory()->create(['code' => 'safety_officer']);
        $bonus = EventBonus::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'bonus_type_id' => $bonusType->id,
            'is_verified' => true,
        ]);

        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Test uncheck revokes bonus',
        ]);
        SafetyChecklistEntry::factory()->completed()->create([
            'safety_checklist_item_id' => $item->id,
        ]);

        $this->actingAs($this->safetyOfficer);

        Livewire::test(SiteSafetyChecklist::class)
            ->call('toggleItem', $item->id)
            ->assertDispatched('toast', title: 'Bonus Revoked');

        expect(EventBonus::find($bonus->id))->toBeNull();
    });

    test('regular user cannot update notes', function () {
        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Cannot update notes item',
        ]);
        $entry = SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item->id,
        ]);

        $this->actingAs($this->regularUser);

        Livewire::test(SiteSafetyChecklist::class)
            ->call('updateNotes', $item->id, 'Should not be saved');

        expect($entry->fresh()->notes)->toBeNull();
    });
});
