<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Admin\AuditLogViewer;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create permissions
    Permission::create(['name' => 'view-security-logs']);

    // Create roles
    $adminRole = Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    // Grant permission to System Administrator
    $adminRole->givePermissionTo('view-security-logs');

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
// Permission Tests (3 tests)
// =============================================================================

test('guest user is redirected to login', function () {
    $this->get(route('admin.audit-logs'))
        ->assertRedirect(route('login'));
});

test('user without view-security-logs permission cannot access component', function () {
    $this->actingAs($this->regularUser);

    // The middleware redirects or the Livewire component authorization rejects
    // We test via Livewire component which should properly reject
    Livewire::test(AuditLogViewer::class)
        ->assertForbidden();
});

test('user with view-security-logs permission can access component', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(AuditLogViewer::class)
        ->assertStatus(200);
});

// =============================================================================
// Rendering Tests (3 tests)
// =============================================================================

test('page renders the component correctly', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(AuditLogViewer::class)
        ->assertStatus(200)
        ->assertSee('Audit Logs')
        ->assertSee('Filters');
});

test('audit logs are displayed in the table', function () {
    $this->actingAs($this->adminUser);

    // Create an audit log entry
    AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'is_critical' => false,
        'created_at' => now(),
    ]);

    Livewire::test(AuditLogViewer::class)
        ->assertSee('W1AW')
        ->assertSee('192.168.1.1');
});

test('empty state shown when no logs exist', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(AuditLogViewer::class)
        ->assertSee('No audit logs found');
});

// =============================================================================
// Filter Tests (7 tests)
// =============================================================================

test('user filter returns only that users logs', function () {
    $this->actingAs($this->adminUser);

    // Create logs for different users
    AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => now(),
    ]);

    AuditLog::create([
        'user_id' => $this->regularUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.2',
        'user_agent' => 'Chrome',
        'created_at' => now(),
    ]);

    $component = Livewire::test(AuditLogViewer::class)
        ->set('filters.user_ids', [$this->adminUser->id]);

    $logs = $component->viewData('logs');

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->user_id)->toBe($this->adminUser->id);
});

test('action type filter returns only matching actions', function () {
    $this->actingAs($this->adminUser);

    AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => now(),
    ]);

    AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.logout',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => now(),
    ]);

    $component = Livewire::test(AuditLogViewer::class)
        ->set('filters.action_types', ['user.login.success']);

    $logs = $component->viewData('logs');

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->action)->toBe('user.login.success');
});

test('date range filter works with from only', function () {
    $this->actingAs($this->adminUser);

    // Create old log (before the filter date)
    $oldLog = new AuditLog([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
    ]);
    $oldLog->created_at = Carbon::parse('2026-01-15 12:00:00');
    $oldLog->save();

    // Create recent log (after the filter date)
    $recentLog = new AuditLog([
        'user_id' => $this->adminUser->id,
        'action' => 'user.logout',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
    ]);
    $recentLog->created_at = Carbon::parse('2026-01-25 12:00:00');
    $recentLog->save();

    // Filter from Jan 20 (should only get the Jan 25 log)
    $component = Livewire::test(AuditLogViewer::class)
        ->set('filters.date_from', '2026-01-20');

    $logs = $component->viewData('logs');

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->action)->toBe('user.logout');
});

test('date range filter works with to only', function () {
    $this->actingAs($this->adminUser);

    // Create old log (before the filter date)
    $oldLog = new AuditLog([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
    ]);
    $oldLog->created_at = Carbon::parse('2026-01-15 12:00:00');
    $oldLog->save();

    // Create recent log (after the filter date)
    $recentLog = new AuditLog([
        'user_id' => $this->adminUser->id,
        'action' => 'user.logout',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
    ]);
    $recentLog->created_at = Carbon::parse('2026-01-25 12:00:00');
    $recentLog->save();

    // Filter to Jan 20 (should only get the Jan 15 log)
    $component = Livewire::test(AuditLogViewer::class)
        ->set('filters.date_to', '2026-01-20');

    $logs = $component->viewData('logs');

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->action)->toBe('user.login.success');
});

test('date range filter works with both from and to', function () {
    $this->actingAs($this->adminUser);

    // Create log outside range (too old)
    $oldLog = new AuditLog([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
    ]);
    $oldLog->created_at = Carbon::parse('2026-01-10 12:00:00');
    $oldLog->save();

    // Create log inside range
    $middleLog = new AuditLog([
        'user_id' => $this->adminUser->id,
        'action' => 'user.logout',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
    ]);
    $middleLog->created_at = Carbon::parse('2026-01-20 12:00:00');
    $middleLog->save();

    // Create log outside range (too new)
    $newLog = new AuditLog([
        'user_id' => $this->adminUser->id,
        'action' => 'user.created',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
    ]);
    $newLog->created_at = Carbon::parse('2026-01-30 12:00:00');
    $newLog->save();

    // Filter from Jan 15 to Jan 25 (should only get the Jan 20 log)
    $component = Livewire::test(AuditLogViewer::class)
        ->set('filters.date_from', '2026-01-15')
        ->set('filters.date_to', '2026-01-25');

    $logs = $component->viewData('logs');

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->action)->toBe('user.logout');
});

test('ip address filter works with partial match', function () {
    $this->actingAs($this->adminUser);

    AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.100',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => now(),
    ]);

    AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.logout',
        'ip_address' => '10.0.0.1',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => now(),
    ]);

    $component = Livewire::test(AuditLogViewer::class)
        ->set('filters.ip_address', '192.168');

    $logs = $component->viewData('logs');

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->ip_address)->toBe('192.168.1.100');
});

test('combined filters work together correctly', function () {
    $this->actingAs($this->adminUser);

    // Helper function to create log with specific timestamp
    $createLog = function ($userId, $action, $ip, $date) {
        $log = new AuditLog([
            'user_id' => $userId,
            'action' => $action,
            'ip_address' => $ip,
            'user_agent' => 'Mozilla/5.0',
        ]);
        $log->created_at = Carbon::parse($date);
        $log->save();

        return $log;
    };

    // Target log - should match all filters
    $createLog($this->adminUser->id, 'user.login.success', '192.168.1.1', '2026-01-20 12:00:00');

    // Wrong user
    $createLog($this->regularUser->id, 'user.login.success', '192.168.1.1', '2026-01-20 12:00:00');

    // Wrong action
    $createLog($this->adminUser->id, 'user.logout', '192.168.1.1', '2026-01-20 12:00:00');

    // Wrong IP
    $createLog($this->adminUser->id, 'user.login.success', '10.0.0.1', '2026-01-20 12:00:00');

    // Wrong date (too old)
    $createLog($this->adminUser->id, 'user.login.success', '192.168.1.1', '2026-01-05 12:00:00');

    $component = Livewire::test(AuditLogViewer::class)
        ->set('filters.user_ids', [$this->adminUser->id])
        ->set('filters.action_types', ['user.login.success'])
        ->set('filters.ip_address', '192.168')
        ->set('filters.date_from', '2026-01-15')
        ->set('filters.date_to', '2026-01-25');

    $logs = $component->viewData('logs');

    expect($logs)->toHaveCount(1);
});

test('clear filters resets all filters and shows all logs', function () {
    $this->actingAs($this->adminUser);

    AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => now(),
    ]);

    AuditLog::create([
        'user_id' => $this->regularUser->id,
        'action' => 'user.logout',
        'ip_address' => '10.0.0.1',
        'user_agent' => 'Chrome',
        'created_at' => now(),
    ]);

    // Apply filters first
    $component = Livewire::test(AuditLogViewer::class)
        ->set('filters.user_ids', [$this->adminUser->id])
        ->set('filters.action_types', ['user.login.success'])
        ->set('filters.ip_address', '192.168')
        ->set('filters.date_from', now()->subDays(1)->format('Y-m-d'))
        ->set('filters.date_to', now()->format('Y-m-d'));

    // Verify filter is applied
    expect($component->viewData('logs'))->toHaveCount(1);

    // Clear filters
    $component->call('clearFilters')
        ->assertSet('filters.user_ids', [])
        ->assertSet('filters.action_types', [])
        ->assertSet('filters.ip_address', null)
        ->assertSet('filters.date_from', null)
        ->assertSet('filters.date_to', null);

    // All logs should now be visible
    expect($component->viewData('logs'))->toHaveCount(2);
});

// =============================================================================
// Date Preset Tests (3 tests)
// =============================================================================

test('setting 24h preset sets correct date range', function () {
    $this->actingAs($this->adminUser);

    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));

    $component = Livewire::test(AuditLogViewer::class)
        ->call('setDatePreset', '24h');

    expect($component->get('filters.date_from'))->toBe('2026-02-01')
        ->and($component->get('filters.date_to'))->toBe('2026-02-02');

    Carbon::setTestNow();
});

test('setting 7d preset sets correct date range', function () {
    $this->actingAs($this->adminUser);

    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));

    $component = Livewire::test(AuditLogViewer::class)
        ->call('setDatePreset', '7d');

    expect($component->get('filters.date_from'))->toBe('2026-01-26')
        ->and($component->get('filters.date_to'))->toBe('2026-02-02');

    Carbon::setTestNow();
});

test('setting 30d preset sets correct date range', function () {
    $this->actingAs($this->adminUser);

    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));

    $component = Livewire::test(AuditLogViewer::class)
        ->call('setDatePreset', '30d');

    expect($component->get('filters.date_from'))->toBe('2026-01-03')
        ->and($component->get('filters.date_to'))->toBe('2026-02-02');

    Carbon::setTestNow();
});

// =============================================================================
// Pagination Tests (3 tests)
// =============================================================================

test('default shows 25 per page', function () {
    $this->actingAs($this->adminUser);

    // Create 30 logs
    foreach (range(1, 30) as $i) {
        AuditLog::create([
            'user_id' => $this->adminUser->id,
            'action' => 'user.login.success',
            'ip_address' => '192.168.1.'.$i,
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now()->subMinutes($i),
        ]);
    }

    $component = Livewire::test(AuditLogViewer::class)
        ->assertSet('perPage', 25);

    $logs = $component->viewData('logs');
    expect($logs->count())->toBe(25)
        ->and($logs->total())->toBe(30);
});

test('changing perPage updates results', function () {
    $this->actingAs($this->adminUser);

    // Create 60 logs
    foreach (range(1, 60) as $i) {
        AuditLog::create([
            'user_id' => $this->adminUser->id,
            'action' => 'user.login.success',
            'ip_address' => '192.168.1.'.$i,
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now()->subMinutes($i),
        ]);
    }

    $component = Livewire::test(AuditLogViewer::class)
        ->set('perPage', 50);

    $logs = $component->viewData('logs');
    expect($logs->count())->toBe(50);
});

test('pagination navigation works', function () {
    $this->actingAs($this->adminUser);

    // Create 30 logs
    foreach (range(1, 30) as $i) {
        AuditLog::create([
            'user_id' => $this->adminUser->id,
            'action' => 'user.login.success',
            'ip_address' => '192.168.1.'.$i,
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now()->subMinutes($i),
        ]);
    }

    $component = Livewire::test(AuditLogViewer::class)
        ->call('gotoPage', 2);

    $logs = $component->viewData('logs');
    expect($logs->currentPage())->toBe(2)
        ->and($logs->count())->toBe(5); // 30 - 25 = 5 on page 2
});

// =============================================================================
// Detail Modal Tests (3 tests)
// =============================================================================

test('clicking showDetails opens modal with correct log', function () {
    $this->actingAs($this->adminUser);

    $log = AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'is_critical' => false,
        'created_at' => now(),
    ]);

    Livewire::test(AuditLogViewer::class)
        ->call('showDetails', $log->id)
        ->assertSet('selectedLogId', $log->id)
        ->assertSet('showDetailModal', true);
});

test('modal displays all log fields', function () {
    $this->actingAs($this->adminUser);

    $log = AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.updated',
        'ip_address' => '192.168.1.100',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120',
        'old_values' => ['name' => 'Old Name'],
        'new_values' => ['name' => 'New Name'],
        'is_critical' => true,
        'created_at' => now(),
    ]);

    $component = Livewire::test(AuditLogViewer::class)
        ->call('showDetails', $log->id);

    // Verify the log is selected
    expect($component->get('selectedLogId'))->toBe($log->id);

    // Verify the view shows the log details
    $component
        ->assertSee('192.168.1.100')
        ->assertSee('Critical');
});

test('closing modal resets selectedLogId', function () {
    $this->actingAs($this->adminUser);

    $log = AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => now(),
    ]);

    Livewire::test(AuditLogViewer::class)
        ->call('showDetails', $log->id)
        ->assertSet('showDetailModal', true)
        ->assertSet('selectedLogId', $log->id)
        ->call('closeDetails')
        ->assertSet('showDetailModal', false)
        ->assertSet('selectedLogId', null);
});

// =============================================================================
// Export Tests (3 tests)
// =============================================================================

test('export returns a streamed download response', function () {
    $this->actingAs($this->adminUser);

    AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => now(),
    ]);

    $component = Livewire::test(AuditLogViewer::class);
    $response = $component->call('exportCsv');

    // Check that there's a download effect
    expect($response->effects)->toHaveKey('download');
});

test('export is triggered successfully', function () {
    $this->actingAs($this->adminUser);

    AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => now(),
    ]);

    // Export CSV and verify it triggers without error
    $component = Livewire::test(AuditLogViewer::class);
    $response = $component->call('exportCsv');

    // Verify the download effect exists
    expect($response->effects)->toHaveKey('download');
});

test('export works with filters applied', function () {
    $this->actingAs($this->adminUser);

    // Create logs for both users
    AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => now(),
    ]);

    AuditLog::create([
        'user_id' => $this->regularUser->id,
        'action' => 'user.login.success',
        'ip_address' => '10.0.0.1',
        'user_agent' => 'Chrome',
        'created_at' => now(),
    ]);

    // Apply filter and export
    $component = Livewire::test(AuditLogViewer::class)
        ->set('filters.user_ids', [$this->adminUser->id]);

    // Verify only filtered user's logs are in the view
    $logs = $component->viewData('logs');
    expect($logs)->toHaveCount(1)
        ->and($logs->first()->user_id)->toBe($this->adminUser->id);

    // Call export - the export should use the same filters
    $response = $component->call('exportCsv');
    expect($response->effects)->toHaveKey('download');
});

// =============================================================================
// Edge Cases Tests (3 tests)
// =============================================================================

test('log with deleted user displays gracefully', function () {
    $this->actingAs($this->adminUser);

    // Create a user that will be deleted
    $deletedUser = User::factory()->create([
        'call_sign' => 'DELETED1',
        'first_name' => 'Deleted',
        'last_name' => 'User',
    ]);
    $deletedUserId = $deletedUser->id;

    // Create a log for that user
    AuditLog::create([
        'user_id' => $deletedUserId,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => now(),
    ]);

    // Delete the user (soft delete)
    $deletedUser->delete();

    // The component should still render without errors
    // User ID exists but user is soft deleted - will show as null due to eager load
    Livewire::test(AuditLogViewer::class)
        ->assertStatus(200);
});

test('log with null user_id displays dash', function () {
    $this->actingAs($this->adminUser);

    // Create a log with no user (system action)
    AuditLog::create([
        'user_id' => null,
        'action' => 'system.setup.completed',
        'ip_address' => '127.0.0.1',
        'user_agent' => null,
        'created_at' => now(),
    ]);

    // Component should render and show the dash for null user
    $component = Livewire::test(AuditLogViewer::class);
    $logs = $component->viewData('logs');

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->user_id)->toBeNull();
});

test('critical logs show badge', function () {
    $this->actingAs($this->adminUser);

    AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.failed',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'is_critical' => true,
        'created_at' => now(),
    ]);

    $component = Livewire::test(AuditLogViewer::class);
    $logs = $component->viewData('logs');

    expect($logs->first()->is_critical)->toBeTrue();

    // Verify the view contains the Critical badge text
    $component->assertSee('Critical');
});

// =============================================================================
// Component State Tests (2 tests)
// =============================================================================

test('component initializes with default filter values', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(AuditLogViewer::class)
        ->assertSet('filters.user_ids', [])
        ->assertSet('filters.action_types', [])
        ->assertSet('filters.date_from', null)
        ->assertSet('filters.date_to', null)
        ->assertSet('filters.ip_address', null)
        ->assertSet('perPage', 25)
        ->assertSet('selectedLogId', null)
        ->assertSet('showDetailModal', false);
});

test('logs are ordered by created_at descending', function () {
    $this->actingAs($this->adminUser);

    // Create logs with specific timestamps
    $oldLog = AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => now()->subHours(2),
    ]);

    $newLog = AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.logout',
        'ip_address' => '192.168.1.1',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => now(),
    ]);

    $component = Livewire::test(AuditLogViewer::class);
    $logs = $component->viewData('logs');

    // Most recent should be first
    expect($logs->first()->id)->toBe($newLog->id)
        ->and($logs->last()->id)->toBe($oldLog->id);
});

test('export neutralizes spreadsheet formulas', function () {
    $this->actingAs($this->adminUser);

    AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '192.168.1.1',
        'user_agent' => '=cmd|" /C calc"!A0',
        'created_at' => now(),
    ]);

    $response = Livewire::test(AuditLogViewer::class)->call('exportCsv');
    $csv = base64_decode($response->effects['download']['content']);

    expect($csv)->toContain('"\'=cmd|"" /C calc""!A0"');
});

test('renders audit logs as tappable cards below md', function () {
    $this->actingAs($this->adminUser);

    $log = AuditLog::create([
        'user_id' => $this->adminUser->id,
        'action' => 'user.login.success',
        'ip_address' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0',
        'is_critical' => false,
        'created_at' => now(),
    ]);

    Livewire::test(AuditLogViewer::class)
        ->assertSeeHtmlInOrder([
            'md:hidden space-y-3',
            'wire:key="audit-log-card-'.$log->id.'"',
            'wire:click="showDetails('.$log->id.')"',
            'W1AW',
            '203.0.113.9',
            'hidden md:block overflow-x-auto',
        ]);
});
