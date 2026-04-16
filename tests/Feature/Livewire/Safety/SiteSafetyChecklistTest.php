<?php

use App\Enums\ChecklistType;
use App\Livewire\Safety\SiteSafetyChecklist;
use App\Models\AuditLog;
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
        'icon' => 'phosphor-shield-check',
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

    test('shows help text toggle button when item has help text', function () {
        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Fire extinguisher on hand',
            'help_text' => 'Place a minimum 5-lb ABC-rated fire extinguisher nearby.',
        ]);
        SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item->id,
        ]);

        $this->actingAs($this->regularUser);

        Livewire::test(SiteSafetyChecklist::class)
            ->assertSee('Fire extinguisher on hand')
            ->assertSee('Place a minimum 5-lb ABC-rated fire extinguisher nearby.');
    });

    test('does not show help text toggle for items without help text', function () {
        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Custom item without help',
            'help_text' => null,
        ]);
        SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item->id,
        ]);

        $this->actingAs($this->regularUser);

        Livewire::test(SiteSafetyChecklist::class)
            ->assertSee('Custom item without help')
            ->assertDontSeeHtml('phosphor-question');
    });

    test('seeded defaults include help text', function () {
        $this->actingAs($this->regularUser);

        Livewire::test(SiteSafetyChecklist::class)
            ->assertStatus(200);

        $itemWithHelp = SafetyChecklistItem::forEvent($this->eventConfig->id)
            ->whereNotNull('help_text')
            ->first();

        expect($itemWithHelp)->not->toBeNull()
            ->and($itemWithHelp->help_text)->toBeString()->not->toBeEmpty();
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

    test('completing all safety officer items auto-claims the bonus', function () {
        $bonusType = BonusType::factory()->create([
            'code' => 'safety_officer',
            'base_points' => 100,
        ]);

        $item1 = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Item A',
        ]);
        SafetyChecklistEntry::factory()->completed()->create([
            'safety_checklist_item_id' => $item1->id,
        ]);

        $item2 = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Item B',
        ]);
        SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item2->id,
        ]);

        $this->actingAs($this->safetyOfficer);

        Livewire::test(SiteSafetyChecklist::class)
            ->call('toggleItem', $item2->id)
            ->assertDispatched('bonus-claimed')
            ->assertDispatched('toast', title: 'Bonus Earned');

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->where('bonus_type_id', $bonusType->id)
            ->first();

        expect($bonus)->not->toBeNull()
            ->and($bonus->calculated_points)->toBe(100)
            ->and($bonus->is_verified)->toBeTrue()
            ->and($bonus->claimed_by_user_id)->toBe($this->safetyOfficer->id);
    });

    test('unchecking an item after all complete revokes the auto-claimed bonus', function () {
        $bonusType = BonusType::factory()->create([
            'code' => 'safety_officer',
            'base_points' => 100,
        ]);

        $item1 = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Item A',
        ]);
        SafetyChecklistEntry::factory()->completed()->create([
            'safety_checklist_item_id' => $item1->id,
        ]);

        $item2 = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Item B',
        ]);
        SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item2->id,
        ]);

        $this->actingAs($this->safetyOfficer);

        // Complete all items to claim bonus
        $component = Livewire::test(SiteSafetyChecklist::class)
            ->call('toggleItem', $item2->id)
            ->assertDispatched('bonus-claimed');

        expect(EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->where('bonus_type_id', $bonusType->id)
            ->exists())->toBeTrue();

        // Uncheck to revoke bonus
        $component->call('toggleItem', $item2->id)
            ->assertDispatched('toast', title: 'Bonus Revoked');

        expect(EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->where('bonus_type_id', $bonusType->id)
            ->exists())->toBeFalse();
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

// =============================================================================
// CPR/AED Trained Personnel Display
// =============================================================================

describe('cpr/aed trained personnel', function () {
    test('shows trained users on CPR/AED checklist item', function () {
        $trainedUser = User::factory()->create([
            'call_sign' => 'KD2CPR',
            'first_name' => 'Medic',
            'is_cpr_aed_trained' => true,
        ]);

        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'First Aid - CPR - AED versed else trained participant/s on site for full Field Day period',
        ]);
        SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item->id,
        ]);

        $this->actingAs($this->regularUser);

        Livewire::test(SiteSafetyChecklist::class)
            ->assertSee('KD2CPR')
            ->assertSee('Medic');
    });

    test('does not show trained users section when no users are trained', function () {
        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'First Aid - CPR - AED versed else trained participant/s on site for full Field Day period',
        ]);
        SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item->id,
        ]);

        $this->actingAs($this->regularUser);

        Livewire::test(SiteSafetyChecklist::class)
            ->assertDontSee('Trained personnel');
    });

    test('does not show trained users on non-CPR checklist items', function () {
        User::factory()->create([
            'call_sign' => 'KD2CPR',
            'first_name' => 'Medic',
            'is_cpr_aed_trained' => true,
        ]);

        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Fire extinguisher on hand',
        ]);
        SafetyChecklistEntry::factory()->create([
            'safety_checklist_item_id' => $item->id,
        ]);

        $this->actingAs($this->regularUser);

        Livewire::test(SiteSafetyChecklist::class)
            ->assertDontSee('Trained personnel');
    });
});

// =============================================================================
// Audit Logging
// =============================================================================

describe('audit logging', function () {
    test('toggling a safety item logs to audit log', function () {
        $this->actingAs($this->safetyOfficer);

        $item = SafetyChecklistItem::create([
            'event_configuration_id' => $this->eventConfig->id,
            'checklist_type' => ChecklistType::SafetyOfficer,
            'label' => 'Fire Extinguisher Check',
            'is_required' => true,
            'is_default' => false,
            'sort_order' => 0,
        ]);
        SafetyChecklistEntry::create([
            'safety_checklist_item_id' => $item->id,
            'is_completed' => false,
        ]);

        Livewire::test(SiteSafetyChecklist::class)
            ->call('toggleItem', $item->id);

        $auditLog = AuditLog::where('action', 'safety.item.toggled')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->user_id)->toBe($this->safetyOfficer->id);
        expect($auditLog->new_values['is_completed'])->toBeTrue();
        expect($auditLog->new_values['label'])->toBe('Fire Extinguisher Check');
    });
});
