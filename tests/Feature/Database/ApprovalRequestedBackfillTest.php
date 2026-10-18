<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the backfill marks locked, never-signed-in registrations as pending approval', function () {
    $migration = require database_path('migrations/2026_10_17_192454_add_approval_requested_at_to_users_table.php');
    $migration->down();

    $insert = fn (array $attributes): int => DB::table('users')->insertGetId(array_merge(
        User::factory()->raw(),
        ['password' => 'x', 'created_at' => now(), 'updated_at' => now()],
        $attributes,
    ));

    $pending = $insert(['account_locked_at' => now(), 'last_login_at' => null]);
    $signedInBefore = $insert(['account_locked_at' => now(), 'last_login_at' => now()->subDay()]);
    $ghost = $insert(['account_locked_at' => now(), 'last_login_at' => null, 'is_ghost' => true]);
    $adminLocked = $insert(['account_locked_at' => now(), 'last_login_at' => null]);
    $unlocked = $insert(['account_locked_at' => null, 'last_login_at' => null]);

    DB::table('audit_logs')->insert([
        'action' => 'user.locked',
        'auditable_type' => User::class,
        'auditable_id' => $adminLocked,
        'ip_address' => '127.0.0.1',
        'created_at' => now(),
    ]);

    $migration->up();

    expect(User::find($pending)->isPendingApproval())->toBeTrue()
        ->and(User::find($signedInBefore)->isPendingApproval())->toBeFalse()
        ->and(User::find($ghost)->isPendingApproval())->toBeFalse()
        ->and(User::find($adminLocked)->isPendingApproval())->toBeFalse()
        ->and(User::find($unlocked)->approval_requested_at)->toBeNull();
});
