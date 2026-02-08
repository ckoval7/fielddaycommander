<?php

use App\Livewire\Admin\DeveloperTools;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\DeveloperClockService;
use Carbon\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Create permissions
    Permission::create(['name' => 'manage-settings']);

    // Create sections (required for realistic contacts)
    Section::factory()->create(['code' => 'CT', 'name' => 'Connecticut']);
    Section::factory()->create(['code' => 'NH', 'name' => 'New Hampshire']);
    Section::factory()->create(['code' => 'MA', 'name' => 'Massachusetts']);
    Section::factory()->create(['code' => 'CA', 'name' => 'California']);
    Section::factory()->create(['code' => 'TX', 'name' => 'Texas']);

    // Create station
    $this->station = Station::factory()->create();

    // Create an administrator role with manage-settings permission
    $adminRole = Role::create(['name' => 'Administrator', 'guard_name' => 'web']);
    $adminRole->givePermissionTo('manage-settings');

    // Create admin user
    $this->admin = User::factory()->create();
    $this->admin->assignRole($adminRole);

    // Create operator role for test users
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);
});

test('seedTestContacts assigns sections to all contacts', function () {
    // Create active event
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Create test users directly (without using Livewire component)
    $operatorRole = Role::where('name', 'Operator')->first();
    for ($i = 0; $i < 10; $i++) {
        $user = User::factory()->create([
            'call_sign' => 'TEST'.($i + 1).chr(65).chr(65),
        ]);
        $user->assignRole($operatorRole);
    }

    // Seed test contacts
    Livewire::actingAs($this->admin)
        ->test(DeveloperTools::class)
        ->call('seedTestContacts');

    // Verify all contacts have non-null section_id
    $contactsWithoutSection = Contact::whereNull('section_id')->count();
    expect($contactsWithoutSection)->toBe(0);

    // Verify that at least one contact has a section (sanity check)
    $contactsWithSection = Contact::whereNotNull('section_id')->count();
    expect($contactsWithSection)->toBe(50);
});

test('seedTestContacts uses appNow() for qso_time when time travel is active', function () {
    // Create active event in the past (time travel scenario)
    $fakeTime = Carbon::create(2026, 6, 28, 14, 0, 0); // June 28, 2026 at 2:00 PM
    $clockService = app(DeveloperClockService::class);
    $clockService->setFakeTime($fakeTime, false);

    $event = Event::factory()->create([
        'start_time' => $fakeTime->copy()->subHours(2),
        'end_time' => $fakeTime->copy()->addHours(26),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Create test users directly (without using Livewire component)
    $operatorRole = Role::where('name', 'Operator')->first();
    for ($i = 0; $i < 10; $i++) {
        $user = User::factory()->create([
            'call_sign' => 'TEST'.($i + 1).chr(65).chr(65),
        ]);
        $user->assignRole($operatorRole);
    }

    // Seed test contacts
    Livewire::actingAs($this->admin)
        ->test(DeveloperTools::class)
        ->call('seedTestContacts');

    // All contacts should have qso_time around the fake time (within event window)
    $contacts = Contact::all();
    expect($contacts)->toHaveCount(50);

    foreach ($contacts as $contact) {
        $qsoTime = Carbon::parse($contact->qso_time);
        // QSO time should be within the event time window
        expect($qsoTime->greaterThanOrEqualTo($event->start_time))->toBeTrue();
        expect($qsoTime->lessThanOrEqualTo($event->end_time))->toBeTrue();
    }

    // Clean up
    $clockService->clearFakeTime();
});

test('seedTestContacts creates contacts with varied qso_times within event window', function () {
    // Create active event
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Create test users directly (without using Livewire component)
    $operatorRole = Role::where('name', 'Operator')->first();
    for ($i = 0; $i < 10; $i++) {
        $user = User::factory()->create([
            'call_sign' => 'TEST'.($i + 1).chr(65).chr(65),
        ]);
        $user->assignRole($operatorRole);
    }

    // Seed test contacts
    Livewire::actingAs($this->admin)
        ->test(DeveloperTools::class)
        ->call('seedTestContacts');

    // Collect all unique qso_times
    $qsoTimes = Contact::distinct('qso_time')->pluck('qso_time');

    // There should be multiple different qso_times (not all the same)
    // With 50 contacts and random times, we should have at least 10 unique times
    expect($qsoTimes->count())->toBeGreaterThanOrEqual(10);
});

test('seedTestContacts fails gracefully when test user pool does not exist', function () {
    // Create active event
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Do NOT initialize test user pool

    // Try to seed test contacts
    Livewire::actingAs($this->admin)
        ->test(DeveloperTools::class)
        ->call('seedTestContacts');

    // Should show an error (verify no contacts were created)
    expect(Contact::count())->toBe(0);
});

test('seedTestContacts fails gracefully when no active event exists', function () {
    // Create test users directly (without using Livewire component)
    $operatorRole = Role::where('name', 'Operator')->first();
    for ($i = 0; $i < 10; $i++) {
        $user = User::factory()->create([
            'call_sign' => 'TEST'.($i + 1).chr(65).chr(65),
        ]);
        $user->assignRole($operatorRole);
    }

    // Do NOT create an active event

    // Try to seed test contacts
    Livewire::actingAs($this->admin)
        ->test(DeveloperTools::class)
        ->call('seedTestContacts');

    // Should show an error (verify no contacts were created)
    expect(Contact::count())->toBe(0);
});

test('seedTestContacts uses only is_active sections when available', function () {
    // Create mix of active and inactive sections
    Section::factory()->create(['code' => 'INACTIVE1', 'is_active' => false]);
    Section::factory()->create(['code' => 'INACTIVE2', 'is_active' => false]);

    // Create active event
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Create test users directly (without using Livewire component)
    $operatorRole = Role::where('name', 'Operator')->first();
    for ($i = 0; $i < 10; $i++) {
        $user = User::factory()->create([
            'call_sign' => 'TEST'.($i + 1).chr(65).chr(65),
        ]);
        $user->assignRole($operatorRole);
    }

    // Seed test contacts
    Livewire::actingAs($this->admin)
        ->test(DeveloperTools::class)
        ->call('seedTestContacts');

    // Get all section IDs used in contacts
    $usedSectionIds = Contact::pluck('section_id')->unique();

    // Get inactive section IDs
    $inactiveSectionIds = Section::where('is_active', false)->pluck('id');

    // No contact should use an inactive section
    foreach ($usedSectionIds as $sectionId) {
        expect($inactiveSectionIds->contains($sectionId))->toBeFalse();
    }
});
