<?php

use App\Models\AuditLog;
use App\Models\CallsignChangeRequest;
use App\Models\User;

describe('CallsignChangeRequest Relationships', function () {
    test('belongs to user', function () {
        $user = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        expect($request->user)
            ->toBeInstanceOf(User::class)
            ->id->toBe($user->id);
    });

    test('belongs to reviewer', function () {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
            'reviewed_by' => $admin->id,
        ]);

        expect($request->reviewer)
            ->toBeInstanceOf(User::class)
            ->id->toBe($admin->id);
    });

    test('reviewer is null when not yet reviewed', function () {
        $user = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        expect($request->reviewer)->toBeNull();
    });
});

describe('isPending()', function () {
    test('returns true when status is pending', function () {
        $user = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        expect($request->isPending())->toBeTrue();
    });

    test('returns false when status is approved', function () {
        $user = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'approved',
        ]);

        expect($request->isPending())->toBeFalse();
    });

    test('returns false when status is rejected', function () {
        $user = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'rejected',
        ]);

        expect($request->isPending())->toBeFalse();
    });
});

describe('approve()', function () {
    test('sets status to approved', function () {
        $user = User::factory()->create(['call_sign' => 'AA1AAA']);
        $admin = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        $request->approve($admin);
        $request->refresh();

        expect($request->status)->toBe('approved');
    });

    test('sets reviewed_by to the admin user', function () {
        $user = User::factory()->create(['call_sign' => 'AA1AAA']);
        $admin = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        $request->approve($admin);
        $request->refresh();

        expect($request->reviewed_by)->toBe($admin->id);
    });

    test('sets reviewed_at timestamp', function () {
        $user = User::factory()->create(['call_sign' => 'AA1AAA']);
        $admin = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        $request->approve($admin);
        $request->refresh();

        expect($request->reviewed_at)->not->toBeNull();
    });

    test('stores admin notes when provided', function () {
        $user = User::factory()->create(['call_sign' => 'AA1AAA']);
        $admin = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        $request->approve($admin, 'Looks good to me.');
        $request->refresh();

        expect($request->admin_notes)->toBe('Looks good to me.');
    });

    test('updates the user call_sign to the new callsign', function () {
        $user = User::factory()->create(['call_sign' => 'AA1AAA']);
        $admin = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        $request->approve($admin);
        $user->refresh();

        expect($user->call_sign)->toBe('BB2BBB');
    });

    test('creates an audit log entry', function () {
        $user = User::factory()->create(['call_sign' => 'AA1AAA']);
        $admin = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        $request->approve($admin);

        $log = AuditLog::where('action', 'user.callsign.changed')
            ->where('user_id', $admin->id)
            ->first();

        expect($log)->not->toBeNull()
            ->and($log->old_values)->toBe(['call_sign' => 'AA1AAA'])
            ->and($log->new_values)->toBe(['call_sign' => 'BB2BBB'])
            ->and($log->is_critical)->toBeTrue();
    });
});

describe('reject()', function () {
    test('sets status to rejected', function () {
        $user = User::factory()->create(['call_sign' => 'AA1AAA']);
        $admin = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        $request->reject($admin);
        $request->refresh();

        expect($request->status)->toBe('rejected');
    });

    test('sets reviewed_by to the admin user', function () {
        $user = User::factory()->create(['call_sign' => 'AA1AAA']);
        $admin = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        $request->reject($admin);
        $request->refresh();

        expect($request->reviewed_by)->toBe($admin->id);
    });

    test('sets reviewed_at timestamp', function () {
        $user = User::factory()->create(['call_sign' => 'AA1AAA']);
        $admin = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        $request->reject($admin);
        $request->refresh();

        expect($request->reviewed_at)->not->toBeNull();
    });

    test('stores admin notes when provided', function () {
        $user = User::factory()->create(['call_sign' => 'AA1AAA']);
        $admin = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        $request->reject($admin, 'Callsign not available.');
        $request->refresh();

        expect($request->admin_notes)->toBe('Callsign not available.');
    });

    test('does not change the user call_sign', function () {
        $user = User::factory()->create(['call_sign' => 'AA1AAA']);
        $admin = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        $request->reject($admin);
        $user->refresh();

        expect($user->call_sign)->toBe('AA1AAA');
    });

    test('creates an audit log entry', function () {
        $user = User::factory()->create(['call_sign' => 'AA1AAA']);
        $admin = User::factory()->create();
        $request = CallsignChangeRequest::create([
            'user_id' => $user->id,
            'old_callsign' => 'AA1AAA',
            'new_callsign' => 'BB2BBB',
            'status' => 'pending',
        ]);

        $request->reject($admin);

        $log = AuditLog::where('action', 'user.callsign.change_rejected')
            ->where('user_id', $admin->id)
            ->first();

        expect($log)->not->toBeNull();
    });
});
