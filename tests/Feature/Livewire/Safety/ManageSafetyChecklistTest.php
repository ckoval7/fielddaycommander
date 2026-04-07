<?php

use App\Enums\ChecklistType;
use App\Livewire\Safety\ManageSafetyChecklist;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\SafetyChecklistEntry;
use App\Models\SafetyChecklistItem;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::create(['name' => 'manage-shifts']);

    $this->event = Event::factory()->create([
        'name' => 'Field Day 2026',
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);

    $this->admin = User::factory()->create([
        'first_name' => 'Admin',
        'last_name' => 'User',
    ]);
    $this->admin->givePermissionTo('manage-shifts');

    $this->regularUser = User::factory()->create([
        'first_name' => 'Regular',
        'last_name' => 'User',
    ]);
});

// =============================================================================
// Access
// =============================================================================

describe('access', function () {
    test('requires manage-shifts permission', function () {
        $this->actingAs($this->regularUser);

        Livewire::test(ManageSafetyChecklist::class)
            ->assertForbidden();
    });

    test('allows users with manage-shifts permission', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageSafetyChecklist::class)
            ->assertStatus(200)
            ->assertSee('Manage Safety Checklist');
    });
});

// =============================================================================
// Item Management
// =============================================================================

describe('item management', function () {
    test('can add a custom item', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageSafetyChecklist::class)
            ->call('openItemModal')
            ->assertSet('showItemModal', true)
            ->set('itemLabel', 'Custom safety item')
            ->set('itemHelpText', 'Some helpful guidance here.')
            ->set('itemIsRequired', true)
            ->set('itemChecklistType', ChecklistType::SafetyOfficer->value)
            ->call('saveItem')
            ->assertSet('showItemModal', false)
            ->assertDispatched('toast', title: 'Success', description: 'Item created successfully');

        $item = SafetyChecklistItem::where('label', 'Custom safety item')->first();
        expect($item)->not->toBeNull();
        expect($item->is_default)->toBeFalse();
        expect($item->is_required)->toBeTrue();
        expect($item->help_text)->toBe('Some helpful guidance here.');
        expect($item->checklist_type)->toBe(ChecklistType::SafetyOfficer);

        // Should also create an entry
        expect(SafetyChecklistEntry::where('safety_checklist_item_id', $item->id)->exists())->toBeTrue();
    });

    test('can edit an item label, help text, and required flag', function () {
        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Original label',
            'help_text' => 'Original help text.',
            'is_required' => false,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSafetyChecklist::class)
            ->call('openItemModal', $item->id)
            ->assertSet('editingItemId', $item->id)
            ->assertSet('itemLabel', 'Original label')
            ->assertSet('itemHelpText', 'Original help text.')
            ->set('itemLabel', 'Updated label')
            ->set('itemHelpText', 'Updated help text.')
            ->set('itemIsRequired', true)
            ->call('saveItem')
            ->assertDispatched('toast', title: 'Success', description: 'Item updated successfully');

        $item->refresh();
        expect($item->label)->toBe('Updated label');
        expect($item->help_text)->toBe('Updated help text.');
        expect($item->is_required)->toBeTrue();
    });

    test('can clear help text when editing', function () {
        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Some item',
            'help_text' => 'Existing help text.',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSafetyChecklist::class)
            ->call('openItemModal', $item->id)
            ->set('itemHelpText', '')
            ->call('saveItem');

        expect($item->fresh()->help_text)->toBeNull();
    });

    test('can soft-delete custom items', function () {
        $item = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Custom item to delete',
            'is_default' => false,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSafetyChecklist::class)
            ->call('deleteItem', $item->id)
            ->assertDispatched('toast', title: 'Success', description: 'Item deleted successfully');

        expect($item->fresh()->trashed())->toBeTrue();
    });

    test('cannot delete default ARRL items', function () {
        $item = SafetyChecklistItem::factory()->safetyOfficer()->default()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Default ARRL item',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSafetyChecklist::class)
            ->call('deleteItem', $item->id)
            ->assertDispatched('toast', title: 'Error', description: 'Cannot delete default ARRL items');

        expect($item->fresh()->trashed())->toBeFalse();
    });

    test('label is required when saving', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageSafetyChecklist::class)
            ->call('openItemModal')
            ->set('itemLabel', '')
            ->set('itemChecklistType', ChecklistType::SafetyOfficer->value)
            ->call('saveItem')
            ->assertHasErrors('itemLabel');
    });

    test('auto-seeds defaults on mount when checklist is empty', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageSafetyChecklist::class);

        // Factory defaults to 1A (Class A), which seeds SafetyOfficer items
        expect(SafetyChecklistItem::forEvent($this->eventConfig->id)->count())->toBeGreaterThan(0);
    });

    test('auto-seeding does not duplicate on repeated mounts', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageSafetyChecklist::class);
        $countAfterFirst = SafetyChecklistItem::forEvent($this->eventConfig->id)->count();

        Livewire::test(ManageSafetyChecklist::class);
        $countAfterSecond = SafetyChecklistItem::forEvent($this->eventConfig->id)->count();

        expect($countAfterSecond)->toBe($countAfterFirst);
    });
});

// =============================================================================
// Reordering
// =============================================================================

describe('reordering', function () {
    test('can move item up', function () {
        $item1 = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'First item',
            'sort_order' => 0,
        ]);
        $item2 = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Second item',
            'sort_order' => 1,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSafetyChecklist::class)
            ->call('moveUp', $item2->id);

        expect($item2->fresh()->sort_order)->toBe(0);
        expect($item1->fresh()->sort_order)->toBe(1);
    });

    test('can move item down', function () {
        $item1 = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'First item',
            'sort_order' => 0,
        ]);
        $item2 = SafetyChecklistItem::factory()->safetyOfficer()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'label' => 'Second item',
            'sort_order' => 1,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ManageSafetyChecklist::class)
            ->call('moveDown', $item1->id);

        expect($item1->fresh()->sort_order)->toBe(1);
        expect($item2->fresh()->sort_order)->toBe(0);
    });
});

// =============================================================================
// Audit Logging
// =============================================================================

describe('audit logging', function () {
    test('creating a safety item logs to audit log', function () {
        $this->actingAs($this->admin);

        Livewire::test(ManageSafetyChecklist::class)
            ->call('openItemModal')
            ->set('itemLabel', 'First Aid Kit Check')
            ->set('itemChecklistType', ChecklistType::SafetyOfficer->value)
            ->set('itemIsRequired', true)
            ->call('saveItem');

        $item = SafetyChecklistItem::where('label', 'First Aid Kit Check')->first();

        $auditLog = AuditLog::where('action', 'safety.item.created')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->user_id)->toBe($this->admin->id);
        expect($auditLog->auditable_type)->toBe(SafetyChecklistItem::class);
        expect($auditLog->auditable_id)->toBe($item->id);
        expect($auditLog->new_values)->toMatchArray([
            'label' => 'First Aid Kit Check',
            'is_required' => true,
        ]);
    });

    test('updating a safety item logs old and new values', function () {
        $this->actingAs($this->admin);

        $item = SafetyChecklistItem::create([
            'event_configuration_id' => $this->eventConfig->id,
            'checklist_type' => ChecklistType::SafetyOfficer,
            'label' => 'Original Label',
            'is_required' => false,
            'is_default' => false,
            'sort_order' => 0,
        ]);
        SafetyChecklistEntry::create([
            'safety_checklist_item_id' => $item->id,
            'is_completed' => false,
        ]);

        Livewire::test(ManageSafetyChecklist::class)
            ->call('openItemModal', $item->id)
            ->set('itemLabel', 'Updated Label')
            ->set('itemIsRequired', true)
            ->call('saveItem');

        $auditLog = AuditLog::where('action', 'safety.item.updated')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->old_values)->toMatchArray([
            'label' => 'Original Label',
            'is_required' => false,
        ]);
        expect($auditLog->new_values)->toMatchArray([
            'label' => 'Updated Label',
            'is_required' => true,
        ]);
    });

    test('deleting a safety item logs to audit log', function () {
        $this->actingAs($this->admin);

        $item = SafetyChecklistItem::create([
            'event_configuration_id' => $this->eventConfig->id,
            'checklist_type' => ChecklistType::SafetyOfficer,
            'label' => 'Delete Me',
            'is_required' => false,
            'is_default' => false,
            'sort_order' => 0,
        ]);

        Livewire::test(ManageSafetyChecklist::class)
            ->call('deleteItem', $item->id);

        $auditLog = AuditLog::where('action', 'safety.item.deleted')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->old_values)->toMatchArray([
            'label' => 'Delete Me',
        ]);
    });
});
