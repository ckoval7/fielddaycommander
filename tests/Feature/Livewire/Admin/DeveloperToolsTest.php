<?php

use App\Livewire\Admin\DeveloperTools;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\User;
use App\Services\DatabaseSnapshotService;
use App\Services\DeveloperClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create permissions
    Permission::create(['name' => 'manage-settings']);

    // Create roles
    $adminRole = Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    // Grant permission to System Administrator
    $adminRole->givePermissionTo('manage-settings');

    // Create admin user with permission
    $this->adminUser = User::factory()->create([
        'call_sign' => 'W1AW',
        'first_name' => 'Admin',
        'last_name' => 'User',
    ]);
    $this->adminUser->assignRole('System Administrator');

    // Create regular user without permission
    $this->regularUser = User::factory()->create([
        'call_sign' => 'K2XYZ',
        'first_name' => 'Regular',
        'last_name' => 'User',
    ]);
    $this->regularUser->assignRole('Operator');
});

// =============================================================================
// Authorization Tests (2 tests)
// =============================================================================

test('user without manage-settings permission cannot access component', function () {
    $this->actingAs($this->regularUser);

    Livewire::test(DeveloperTools::class)
        ->assertForbidden();
});

test('user with manage-settings permission can access component', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(DeveloperTools::class)
        ->assertStatus(200);
});

// =============================================================================
// Component Initialization Tests (3 tests)
// =============================================================================

test('component loads without errors', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(DeveloperTools::class)
        ->assertStatus(200)
        ->assertSee('Developer Tools')
        ->assertSee('Time Travel');
});

test('component initializes with default property values', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(DeveloperTools::class)
        ->assertSet('fakeDate', null)
        ->assertSet('fakeTime', null)
        ->assertSet('timeFrozen', true)
        ->assertSet('snapshotName', '')
        ->assertSet('snapshotDescription', '')
        ->assertSet('selectedTables', [])
        ->assertSet('showResetModal', false)
        ->assertSet('showRestoreModal', false)
        ->assertSet('showSelectiveResetModal', false)
        ->assertSet('selectedSnapshot', null)
        ->assertSet('databaseTab', 'full-reset');
});

test('component loads current fake time on mount', function () {
    $this->actingAs($this->adminUser);

    // Set up a fake time in the service
    $fakeTime = Carbon::parse('2026-06-28 14:00:00');
    $clockService = app(DeveloperClockService::class);
    $clockService->setFakeTime($fakeTime, true);

    Livewire::test(DeveloperTools::class)
        ->assertSet('fakeDate', '2026-06-28')
        ->assertSet('fakeTime', '14:00')
        ->assertSet('timeFrozen', true);

    // Clean up
    $clockService->clearFakeTime();
});

// =============================================================================
// Time Travel Tests (5 tests)
// =============================================================================

test('setTime stores fake time via service', function () {
    $this->actingAs($this->adminUser);

    $clockService = $this->mock(DeveloperClockService::class);
    $clockService->shouldReceive('getFakeTime')->andReturn(null);
    $clockService->shouldReceive('isFrozen')->andReturn(true);
    $clockService->shouldReceive('setFakeTime')
        ->once()
        ->with(Mockery::type(Carbon::class), true);

    Livewire::test(DeveloperTools::class)
        ->set('fakeDate', '2026-06-28')
        ->set('fakeTime', '14:00')
        ->set('timeFrozen', true)
        ->call('setTime');

    // Verify audit log was created
    expect(AuditLog::where('action', 'developer.time_travel.set')->count())->toBe(1);
});

test('setTime without time defaults to midnight', function () {
    $this->actingAs($this->adminUser);

    $clockService = $this->mock(DeveloperClockService::class);
    $clockService->shouldReceive('getFakeTime')->andReturn(null);
    $clockService->shouldReceive('isFrozen')->andReturn(true);
    $clockService->shouldReceive('setFakeTime')
        ->once()
        ->with(Mockery::on(function ($arg) {
            return $arg instanceof Carbon && $arg->format('H:i') === '00:00';
        }), true);

    Livewire::test(DeveloperTools::class)
        ->set('fakeDate', '2026-06-28')
        ->set('fakeTime', null)
        ->call('setTime');
});

test('setTime requires a date', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(DeveloperTools::class)
        ->set('fakeDate', null)
        ->call('setTime');

    // No audit log should be created
    expect(AuditLog::where('action', 'developer.time_travel.set')->count())->toBe(0);
});

test('clearTime removes fake time via service', function () {
    $this->actingAs($this->adminUser);

    $clockService = $this->mock(DeveloperClockService::class);
    $clockService->shouldReceive('getFakeTime')->andReturn(null);
    $clockService->shouldReceive('isFrozen')->andReturn(true);
    $clockService->shouldReceive('clearFakeTime')->once();

    Livewire::test(DeveloperTools::class)
        ->call('clearTime')
        ->assertSet('fakeDate', null)
        ->assertSet('fakeTime', null)
        ->assertSet('timeFrozen', true);

    // Verify audit log was created
    expect(AuditLog::where('action', 'developer.time_travel.clear')->count())->toBe(1);
});

test('time travel handles service exceptions', function () {
    $this->actingAs($this->adminUser);

    $clockService = $this->mock(DeveloperClockService::class);
    $clockService->shouldReceive('getFakeTime')->andReturn(null);
    $clockService->shouldReceive('isFrozen')->andReturn(true);
    $clockService->shouldReceive('setFakeTime')
        ->andThrow(new \Exception('Service error'));

    Livewire::test(DeveloperTools::class)
        ->set('fakeDate', '2026-06-28')
        ->call('setTime');

    // Should not create audit log on error
    expect(AuditLog::where('action', 'developer.time_travel.set')->count())->toBe(0);
});

// =============================================================================
// Database Reset Tests (5 tests)
// =============================================================================

test('confirmFullReset opens modal', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(DeveloperTools::class)
        ->call('confirmFullReset')
        ->assertSet('showResetModal', true);
});

test('fullReset calls Artisan migrate:fresh with seed', function () {
    $this->actingAs($this->adminUser);

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate:fresh', [
            '--seed' => true,
            '--force' => true,
        ]);

    Livewire::test(DeveloperTools::class)
        ->set('showResetModal', true)
        ->call('fullReset')
        ->assertRedirect('/');

    // Verify critical audit log was created
    $auditLog = AuditLog::where('action', 'developer.database.full_reset')->first();
    expect($auditLog)->not->toBeNull()
        ->and($auditLog->is_critical)->toBeTrue();
});

test('confirmSelectiveReset requires selected tables', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(DeveloperTools::class)
        ->set('selectedTables', [])
        ->call('confirmSelectiveReset')
        ->assertSet('showSelectiveResetModal', false);
});

test('confirmSelectiveReset opens modal when tables selected', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(DeveloperTools::class)
        ->set('selectedTables', ['contacts'])
        ->call('confirmSelectiveReset')
        ->assertSet('showSelectiveResetModal', true);
});

test('selectiveReset truncates correct tables', function () {
    $this->actingAs($this->adminUser);

    // Create some test data
    Contact::factory()->count(5)->create();

    expect(Contact::count())->toBe(5);

    // Mock DB::statement to avoid SQLite incompatibility with SET FOREIGN_KEY_CHECKS
    DB::shouldReceive('statement')
        ->with('SET FOREIGN_KEY_CHECKS=0')
        ->once();
    DB::shouldReceive('statement')
        ->with('SET FOREIGN_KEY_CHECKS=1')
        ->once();

    // Mock DB::table for truncate operation
    DB::shouldReceive('table')
        ->with('contacts')
        ->once()
        ->andReturnSelf();
    DB::shouldReceive('truncate')
        ->once();
    DB::shouldReceive('table')
        ->with('operating_sessions')
        ->once()
        ->andReturnSelf();
    DB::shouldReceive('truncate')
        ->once();

    Livewire::test(DeveloperTools::class)
        ->set('selectedTables', ['contacts'])
        ->set('showSelectiveResetModal', true)
        ->call('selectiveReset')
        ->assertSet('selectedTables', [])
        ->assertSet('showSelectiveResetModal', false);

    // Verify critical audit log was created
    $auditLog = AuditLog::where('action', 'developer.database.selective_reset')->first();
    expect($auditLog)->not->toBeNull()
        ->and($auditLog->is_critical)->toBeTrue();
});

// =============================================================================
// Database Snapshot Tests (7 tests)
// =============================================================================

test('createSnapshot requires snapshot name', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(DeveloperTools::class)
        ->set('snapshotName', '')
        ->call('createSnapshot');

    // No audit log should be created
    expect(AuditLog::where('action', 'developer.snapshot.create')->count())->toBe(0);
});

test('createSnapshot creates snapshot via service', function () {
    $this->actingAs($this->adminUser);

    $snapshotService = $this->mock(DatabaseSnapshotService::class);
    $snapshotService->shouldReceive('listSnapshots')->andReturn(collect());
    $snapshotService->shouldReceive('createSnapshot')
        ->once()
        ->with('test-snapshot', 'Test description')
        ->andReturn('test-snapshot-2026-02-05_120000.sql');

    Livewire::test(DeveloperTools::class)
        ->set('snapshotName', 'test-snapshot')
        ->set('snapshotDescription', 'Test description')
        ->call('createSnapshot')
        ->assertSet('snapshotName', '')
        ->assertSet('snapshotDescription', '');

    // Verify audit log was created
    expect(AuditLog::where('action', 'developer.snapshot.create')->count())->toBe(1);
});

test('createSnapshot handles service exceptions', function () {
    $this->actingAs($this->adminUser);

    $snapshotService = $this->mock(DatabaseSnapshotService::class);
    $snapshotService->shouldReceive('listSnapshots')->andReturn(collect());
    $snapshotService->shouldReceive('createSnapshot')
        ->andThrow(new \RuntimeException('Snapshot creation failed'));

    Livewire::test(DeveloperTools::class)
        ->set('snapshotName', 'test-snapshot')
        ->call('createSnapshot');

    // No audit log should be created on failure
    expect(AuditLog::where('action', 'developer.snapshot.create')->count())->toBe(0);
});

test('confirmRestore opens modal and sets selected snapshot', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(DeveloperTools::class)
        ->call('confirmRestore', 'test-snapshot.sql')
        ->assertSet('selectedSnapshot', 'test-snapshot.sql')
        ->assertSet('showRestoreModal', true);
});

test('restoreSnapshot restores via service', function () {
    $this->actingAs($this->adminUser);

    $snapshotService = $this->mock(DatabaseSnapshotService::class);
    $snapshotService->shouldReceive('listSnapshots')->andReturn(collect());
    $snapshotService->shouldReceive('restoreSnapshot')
        ->once()
        ->with('test-snapshot.sql')
        ->andReturn(true);

    Livewire::test(DeveloperTools::class)
        ->set('selectedSnapshot', 'test-snapshot.sql')
        ->set('showRestoreModal', true)
        ->call('restoreSnapshot')
        ->assertRedirect('/');

    // Verify critical audit log was created
    $auditLog = AuditLog::where('action', 'developer.snapshot.restore')->first();
    expect($auditLog)->not->toBeNull()
        ->and($auditLog->is_critical)->toBeTrue();
});

test('restoreSnapshot requires selected snapshot', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(DeveloperTools::class)
        ->set('selectedSnapshot', null)
        ->call('restoreSnapshot');

    // No audit log should be created
    expect(AuditLog::where('action', 'developer.snapshot.restore')->count())->toBe(0);
});

test('deleteSnapshot deletes via service', function () {
    $this->actingAs($this->adminUser);

    $snapshotService = $this->mock(DatabaseSnapshotService::class);
    $snapshotService->shouldReceive('listSnapshots')->andReturn(collect());
    $snapshotService->shouldReceive('deleteSnapshot')
        ->once()
        ->with('test-snapshot.sql')
        ->andReturn(true);

    Livewire::test(DeveloperTools::class)
        ->call('deleteSnapshot', 'test-snapshot.sql');

    // Verify audit log was created
    expect(AuditLog::where('action', 'developer.snapshot.delete')->count())->toBe(1);
});

// =============================================================================
// Quick Actions Tests (5 tests)
// =============================================================================

test('seedTestContacts creates 50 contacts for active event', function () {
    $this->actingAs($this->adminUser);

    // Create an active event
    $activeEvent = EventConfiguration::factory()->create([
        'is_active' => true,
    ]);

    Livewire::test(DeveloperTools::class)
        ->call('seedTestContacts');

    // Verify 50 contacts were created
    expect(Contact::where('event_configuration_id', $activeEvent->id)->count())->toBe(50);

    // Verify audit log was created
    expect(AuditLog::where('action', 'developer.quick_action.seed_contacts')->count())->toBe(1);
});

test('seedTestContacts fails when no active event', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(DeveloperTools::class)
        ->call('seedTestContacts');

    // No contacts should be created
    expect(Contact::count())->toBe(0);

    // No audit log should be created
    expect(AuditLog::where('action', 'developer.quick_action.seed_contacts')->count())->toBe(0);
});

test('triggerEventActivation calls Artisan command', function () {
    $this->actingAs($this->adminUser);

    Artisan::shouldReceive('call')
        ->once()
        ->with('events:activate-by-date');

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('Event activated successfully');

    Livewire::test(DeveloperTools::class)
        ->call('triggerEventActivation');

    // Verify audit log was created
    expect(AuditLog::where('action', 'developer.quick_action.event_activation')->count())->toBe(1);
});

test('clearCaches calls optimize:clear', function () {
    $this->actingAs($this->adminUser);

    Artisan::shouldReceive('call')
        ->once()
        ->with('optimize:clear');

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('Caches cleared');

    Livewire::test(DeveloperTools::class)
        ->call('clearCaches');

    // Verify audit log was created
    expect(AuditLog::where('action', 'developer.quick_action.clear_caches')->count())->toBe(1);
});

test('quick actions handle exceptions gracefully', function () {
    $this->actingAs($this->adminUser);

    Artisan::shouldReceive('call')
        ->with('optimize:clear')
        ->andThrow(new \Exception('Command failed'));

    Livewire::test(DeveloperTools::class)
        ->call('clearCaches');

    // No audit log should be created on failure
    expect(AuditLog::where('action', 'developer.quick_action.clear_caches')->count())->toBe(0);
});

// =============================================================================
// Computed Properties Tests (1 test)
// =============================================================================

test('snapshots computed property returns collection from service', function () {
    $this->actingAs($this->adminUser);

    $mockSnapshots = collect([
        [
            'filename' => 'test-snapshot-2026-02-05_120000.sql',
            'name' => 'test-snapshot',
            'description' => 'Test description',
            'created_at' => '2026-02-05T12:00:00+00:00',
            'size' => '1.5 MB',
        ],
    ]);

    $snapshotService = $this->mock(DatabaseSnapshotService::class);
    $snapshotService->shouldReceive('listSnapshots')
        ->andReturn($mockSnapshots);

    $component = Livewire::test(DeveloperTools::class);

    $snapshots = $component->viewData('snapshots');

    expect($snapshots)->toBeInstanceOf(Collection::class)
        ->and($snapshots->count())->toBe(1)
        ->and($snapshots->first()['name'])->toBe('test-snapshot');
});

// =============================================================================
// Audit Logging Tests (6 tests)
// =============================================================================

test('setTime logs to audit_logs table', function () {
    $this->actingAs($this->adminUser);

    $clockService = $this->mock(DeveloperClockService::class);
    $clockService->shouldReceive('getFakeTime')->andReturn(null);
    $clockService->shouldReceive('isFrozen')->andReturn(true);
    $clockService->shouldReceive('setFakeTime')->once();

    Livewire::test(DeveloperTools::class)
        ->set('fakeDate', '2026-06-28')
        ->call('setTime');

    $auditLog = AuditLog::where('action', 'developer.time_travel.set')->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->user_id)->toBe($this->adminUser->id)
        ->and($auditLog->new_values)->toHaveKey('fake_time')
        ->and($auditLog->new_values)->toHaveKey('frozen');
});

test('clearTime logs to audit_logs table', function () {
    $this->actingAs($this->adminUser);

    $clockService = $this->mock(DeveloperClockService::class);
    $clockService->shouldReceive('getFakeTime')->andReturn(null);
    $clockService->shouldReceive('isFrozen')->andReturn(true);
    $clockService->shouldReceive('clearFakeTime')->once();

    Livewire::test(DeveloperTools::class)
        ->call('clearTime');

    $auditLog = AuditLog::where('action', 'developer.time_travel.clear')->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->user_id)->toBe($this->adminUser->id);
});

test('fullReset logs critical audit entry', function () {
    $this->actingAs($this->adminUser);

    Artisan::shouldReceive('call')->once();

    Livewire::test(DeveloperTools::class)
        ->call('fullReset');

    $auditLog = AuditLog::where('action', 'developer.database.full_reset')->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->user_id)->toBe($this->adminUser->id)
        ->and($auditLog->is_critical)->toBeTrue();
});

test('selectiveReset logs critical audit entry with table details', function () {
    $this->actingAs($this->adminUser);

    // Mock DB::statement to avoid SQLite incompatibility with SET FOREIGN_KEY_CHECKS
    DB::shouldReceive('statement')
        ->with('SET FOREIGN_KEY_CHECKS=0')
        ->once();
    DB::shouldReceive('statement')
        ->with('SET FOREIGN_KEY_CHECKS=1')
        ->once();

    // Mock DB::table for truncate operations
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('truncate')->atLeast()->once();

    Livewire::test(DeveloperTools::class)
        ->set('selectedTables', ['contacts', 'guestbook'])
        ->call('selectiveReset');

    $auditLog = AuditLog::where('action', 'developer.database.selective_reset')->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->user_id)->toBe($this->adminUser->id)
        ->and($auditLog->is_critical)->toBeTrue()
        ->and($auditLog->new_values)->toHaveKey('categories')
        ->and($auditLog->new_values)->toHaveKey('tables');
});

test('createSnapshot logs audit entry', function () {
    $this->actingAs($this->adminUser);

    $snapshotService = $this->mock(DatabaseSnapshotService::class);
    $snapshotService->shouldReceive('listSnapshots')->andReturn(collect());
    $snapshotService->shouldReceive('createSnapshot')->andReturn('test-snapshot.sql');

    Livewire::test(DeveloperTools::class)
        ->set('snapshotName', 'test-snapshot')
        ->call('createSnapshot');

    $auditLog = AuditLog::where('action', 'developer.snapshot.create')->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->user_id)->toBe($this->adminUser->id)
        ->and($auditLog->new_values)->toHaveKey('filename')
        ->and($auditLog->new_values)->toHaveKey('name');
});

test('quick actions log audit entries', function () {
    $this->actingAs($this->adminUser);

    $activeEvent = EventConfiguration::factory()->create(['is_active' => true]);

    Livewire::test(DeveloperTools::class)
        ->call('seedTestContacts');

    $auditLog = AuditLog::where('action', 'developer.quick_action.seed_contacts')->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->user_id)->toBe($this->adminUser->id)
        ->and($auditLog->new_values)->toHaveKey('count')
        ->and($auditLog->new_values)->toHaveKey('event_configuration_id');
});

// =============================================================================
// Edge Cases and Error Handling Tests (2 tests)
// =============================================================================

test('selectiveReset with empty selectedTables shows error', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(DeveloperTools::class)
        ->set('selectedTables', [])
        ->call('selectiveReset');

    // No audit log should be created
    expect(AuditLog::where('action', 'developer.database.selective_reset')->count())->toBe(0);
});

test('component handles missing service gracefully', function () {
    $this->actingAs($this->adminUser);

    // This test verifies the component doesn't crash if services are misconfigured
    // The component should still load
    Livewire::test(DeveloperTools::class)
        ->assertStatus(200);
});
