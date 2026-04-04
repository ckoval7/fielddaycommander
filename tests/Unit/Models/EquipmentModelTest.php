<?php

use App\Models\Band;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);
    $this->organization = Organization::factory()->create();
});

describe('Casts', function () {
    test('tags are cast to array', function () {
        $equipment = Equipment::factory()->create([
            'tags' => ['portable', 'hf'],
        ]);

        expect($equipment->tags)->toBeArray()
            ->and($equipment->tags)->toBe(['portable', 'hf']);
    });

    test('value_usd is cast to decimal with 2 places', function () {
        $equipment = Equipment::factory()->create([
            'value_usd' => 1234.56,
        ]);

        expect($equipment->value_usd)->toBe('1234.56');
    });

    test('power_output_watts is cast to integer', function () {
        $equipment = Equipment::factory()->create([
            'power_output_watts' => '100',
        ]);

        expect($equipment->power_output_watts)->toBeInt()
            ->and($equipment->power_output_watts)->toBe(100);
    });
});

describe('Relationships', function () {
    test('belongs to owner user', function () {
        $equipment = Equipment::factory()->create([
            'owner_user_id' => $this->user->id,
        ]);

        expect($equipment->owner)->toBeInstanceOf(User::class)
            ->and($equipment->owner->id)->toBe($this->user->id);
    });

    test('belongs to owning organization', function () {
        $equipment = Equipment::factory()->create([
            'owner_organization_id' => $this->organization->id,
        ]);

        expect($equipment->owningOrganization)->toBeInstanceOf(Organization::class)
            ->and($equipment->owningOrganization->id)->toBe($this->organization->id);
    });

    test('belongs to manager user', function () {
        $manager = User::factory()->create();
        $equipment = Equipment::factory()->create([
            'managed_by_user_id' => $manager->id,
        ]);

        expect($equipment->manager)->toBeInstanceOf(User::class)
            ->and($equipment->manager->id)->toBe($manager->id);
    });

    test('belongs to many bands', function () {
        $equipment = Equipment::factory()->create();
        $band1 = Band::factory()->create(['name' => '20m']);
        $band2 = Band::factory()->create(['name' => '40m']);

        $equipment->bands()->attach([$band1->id, $band2->id]);

        expect($equipment->bands)->toHaveCount(2)
            ->and($equipment->bands->pluck('name')->toArray())->toMatchArray(['20m', '40m']);
    });

    test('has many commitments', function () {
        $equipment = Equipment::factory()->create();
        $event1 = Event::factory()->create();
        $event2 = Event::factory()->create();

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $event1->id,
        ]);
        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $event2->id,
        ]);

        expect($equipment->commitments)->toHaveCount(2)
            ->each->toBeInstanceOf(EquipmentEvent::class);
    });
});

describe('Scopes', function () {
    test('scopeOwnedByUser filters by user owner', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Equipment::factory()->create(['owner_user_id' => $user1->id]);
        Equipment::factory()->create(['owner_user_id' => $user1->id]);
        Equipment::factory()->create(['owner_user_id' => $user2->id]);

        $equipment = Equipment::ownedByUser($user1->id)->get();

        expect($equipment)->toHaveCount(2);
        $equipment->each(fn ($item) => expect($item->owner_user_id)->toBe($user1->id));
    });

    test('scopeOwnedByOrganization filters by organization owner', function () {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        Equipment::factory()->organizationOwned()->create(['owner_organization_id' => $org1->id]);
        Equipment::factory()->organizationOwned()->create(['owner_organization_id' => $org1->id]);
        Equipment::factory()->organizationOwned()->create(['owner_organization_id' => $org2->id]);

        $equipment = Equipment::ownedByOrganization($org1->id)->get();

        expect($equipment)->toHaveCount(2);
        $equipment->each(fn ($item) => expect($item->owner_organization_id)->toBe($org1->id));
    });

    test('scopeOfType filters by equipment type', function () {
        Equipment::factory()->create(['type' => 'radio']);
        Equipment::factory()->create(['type' => 'radio']);
        Equipment::factory()->create(['type' => 'antenna']);

        $equipment = Equipment::ofType('radio')->get();

        expect($equipment)->toHaveCount(2);
        $equipment->each(fn ($item) => expect($item->type)->toBe('radio'));
    });

    test('scopeWithBand filters equipment supporting specific band', function () {
        $band20m = Band::factory()->create(['name' => '20m']);
        $band40m = Band::factory()->create(['name' => '40m']);

        $equipment1 = Equipment::factory()->create();
        $equipment2 = Equipment::factory()->create();
        $equipment3 = Equipment::factory()->create();

        $equipment1->bands()->attach($band20m->id);
        $equipment2->bands()->attach([$band20m->id, $band40m->id]);
        $equipment3->bands()->attach($band40m->id);

        $equipment = Equipment::withBand($band20m->id)->get();

        expect($equipment)->toHaveCount(2)
            ->and($equipment->pluck('id')->toArray())->toMatchArray([
                $equipment1->id,
                $equipment2->id,
            ]);
    });

    test('scopeSearch filters by make', function () {
        Equipment::factory()->create(['make' => 'Yaesu', 'model' => 'FT-991A']);
        Equipment::factory()->create(['make' => 'Icom', 'model' => 'IC-7300']);

        $results = Equipment::search('Yaesu')->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->make)->toBe('Yaesu');
    });

    test('scopeSearch filters by model', function () {
        Equipment::factory()->create(['make' => 'Yaesu', 'model' => 'FT-991A']);
        Equipment::factory()->create(['make' => 'Icom', 'model' => 'IC-7300']);

        $results = Equipment::search('IC-7300')->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->model)->toBe('IC-7300');
    });

    test('scopeSearch filters by description', function () {
        Equipment::factory()->create(['description' => 'Portable HF transceiver']);
        Equipment::factory()->create(['description' => 'Base station amplifier']);

        $results = Equipment::search('transceiver')->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->description)->toBe('Portable HF transceiver');
    });

    test('scopeSearch filters by serial number', function () {
        Equipment::factory()->create(['serial_number' => 'SN-12345']);
        Equipment::factory()->create(['serial_number' => 'SN-67890']);

        $results = Equipment::search('12345')->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->serial_number)->toBe('SN-12345');
    });

    test('scopeWithCommitmentStatus filters available equipment', function () {
        $available = Equipment::factory()->create();
        $committed = Equipment::factory()->create();

        $activeEvent = Event::factory()->create([
            'start_time' => now()->addDays(5),
            'end_time' => now()->addDays(7),
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $committed->id,
            'event_id' => $activeEvent->id,
            'status' => 'committed',
        ]);

        $results = Equipment::withCommitmentStatus('available')->get();

        expect($results->pluck('id')->toArray())->toContain($available->id)
            ->and($results->pluck('id')->toArray())->not->toContain($committed->id);
    });

    test('scopeWithCommitmentStatus filters committed equipment', function () {
        $available = Equipment::factory()->create();
        $committed = Equipment::factory()->create();

        $activeEvent = Event::factory()->create([
            'start_time' => now()->addDays(5),
            'end_time' => now()->addDays(7),
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $committed->id,
            'event_id' => $activeEvent->id,
            'status' => 'committed',
        ]);

        $results = Equipment::withCommitmentStatus('committed')->get();

        expect($results->pluck('id')->toArray())->toContain($committed->id)
            ->and($results->pluck('id')->toArray())->not->toContain($available->id);
    });

    test('scopeWithCommitmentStatus ignores cancelled commitments', function () {
        $equipment = Equipment::factory()->create();

        $activeEvent = Event::factory()->create([
            'start_time' => now()->addDays(5),
            'end_time' => now()->addDays(7),
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $activeEvent->id,
            'status' => 'cancelled',
        ]);

        $results = Equipment::withCommitmentStatus('available')->get();

        expect($results->pluck('id')->toArray())->toContain($equipment->id);
    });

    test('scopeWithCommitmentStatus returns all for unknown status', function () {
        Equipment::factory()->count(3)->create();

        $results = Equipment::withCommitmentStatus('unknown')->get();

        expect($results)->toHaveCount(3);
    });

    test('scopeAvailableForEvent excludes equipment with overlapping commitments', function () {
        // Create events with different time ranges
        $targetEvent = Event::factory()->create([
            'start_time' => now()->addDays(10),
            'end_time' => now()->addDays(12),
        ]);

        $overlappingEvent = Event::factory()->create([
            'start_time' => now()->addDays(11),
            'end_time' => now()->addDays(13),
        ]);

        $nonOverlappingEvent = Event::factory()->create([
            'start_time' => now()->addDays(20),
            'end_time' => now()->addDays(22),
        ]);

        // Create equipment
        $equipment1 = Equipment::factory()->create(); // Available
        $equipment2 = Equipment::factory()->create(); // Committed to overlapping event
        $equipment3 = Equipment::factory()->create(); // Committed to non-overlapping event

        // Create commitments
        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment2->id,
            'event_id' => $overlappingEvent->id,
            'status' => 'committed',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment3->id,
            'event_id' => $nonOverlappingEvent->id,
            'status' => 'committed',
        ]);

        $available = Equipment::availableForEvent($targetEvent->id)->get();

        expect($available->pluck('id')->toArray())->toMatchArray([
            $equipment1->id,
            $equipment3->id,
        ]);
    });

    test('scopeAvailableForEvent includes equipment with cancelled commitments', function () {
        $targetEvent = Event::factory()->create([
            'start_time' => now()->addDays(10),
            'end_time' => now()->addDays(12),
        ]);

        $overlappingEvent = Event::factory()->create([
            'start_time' => now()->addDays(11),
            'end_time' => now()->addDays(13),
        ]);

        $equipment = Equipment::factory()->create();

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $overlappingEvent->id,
            'status' => 'cancelled',
        ]);

        $available = Equipment::availableForEvent($targetEvent->id)->get();

        expect($available->pluck('id'))->toContain($equipment->id);
    });
});

describe('Accessors', function () {
    test('owner_name returns user full name for user-owned equipment', function () {
        $user = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $equipment = Equipment::factory()->create([
            'owner_user_id' => $user->id,
            'owner_organization_id' => null,
        ]);

        expect($equipment->owner_name)->toBe('Jane Smith');
    });

    test('owner_name returns Club Equipment for organization-owned equipment', function () {
        $equipment = Equipment::factory()->organizationOwned()->create([
            'owner_organization_id' => $this->organization->id,
        ]);

        expect($equipment->owner_name)->toBe('Club Equipment');
    });

    test('owner_name returns Unknown Owner when no owner is set', function () {
        // Create with explicit nulls using DB insert to bypass factory defaults
        $equipment = new Equipment;
        $equipment->owner_user_id = null;
        $equipment->owner_organization_id = null;
        $equipment->type = 'other';
        $equipment->save();

        expect($equipment->owner_name)->toBe('Unknown Owner');
    });

    test('is_club_equipment returns true for organization-owned equipment', function () {
        $equipment = Equipment::factory()->organizationOwned()->create([
            'owner_organization_id' => $this->organization->id,
        ]);

        expect($equipment->is_club_equipment)->toBeTrue();
    });

    test('is_club_equipment returns false for user-owned equipment', function () {
        $equipment = Equipment::factory()->create([
            'owner_user_id' => $this->user->id,
            'owner_organization_id' => null,
        ]);

        expect($equipment->is_club_equipment)->toBeFalse();
    });

    test('current_commitment returns active commitment for upcoming event', function () {
        $equipment = Equipment::factory()->create();

        $futureEvent = Event::factory()->create([
            'start_time' => now()->addDays(5),
            'end_time' => now()->addDays(7),
        ]);

        $commitment = EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $futureEvent->id,
            'status' => 'committed',
        ]);

        // Refresh to load relationship
        $equipment->refresh();

        expect($equipment->current_commitment)->toBeInstanceOf(EquipmentEvent::class)
            ->and($equipment->current_commitment->id)->toBe($commitment->id);
    });

    test('current_commitment returns null for cancelled commitments', function () {
        $equipment = Equipment::factory()->create();

        $futureEvent = Event::factory()->create([
            'start_time' => now()->addDays(5),
            'end_time' => now()->addDays(7),
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $futureEvent->id,
            'status' => 'cancelled',
        ]);

        // Refresh to load relationship
        $equipment->refresh();

        expect($equipment->current_commitment)->toBeNull();
    });

    test('current_commitment returns null for past events', function () {
        $equipment = Equipment::factory()->create();

        $pastEvent = Event::factory()->create([
            'start_time' => now()->subDays(7),
            'end_time' => now()->subDays(5),
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $pastEvent->id,
            'status' => 'committed',
        ]);

        // Refresh to load relationship
        $equipment->refresh();

        expect($equipment->current_commitment)->toBeNull();
    });
});
