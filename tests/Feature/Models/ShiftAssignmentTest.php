<?php

use App\Models\BonusType;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use Illuminate\Database\QueryException;

describe('Relationships', function () {
    test('belongs to shift', function () {
        $shift = Shift::factory()->create();
        $assignment = ShiftAssignment::factory()->create(['shift_id' => $shift->id]);

        expect($assignment->shift)
            ->toBeInstanceOf(Shift::class)
            ->id->toBe($shift->id);
    });

    test('belongs to user', function () {
        $user = User::factory()->create();
        $assignment = ShiftAssignment::factory()->create(['user_id' => $user->id]);

        expect($assignment->user)
            ->toBeInstanceOf(User::class)
            ->id->toBe($user->id);
    });

    test('belongs to confirming manager', function () {
        $manager = User::factory()->create();
        $assignment = ShiftAssignment::factory()->confirmed($manager)->create();

        expect($assignment->confirmedBy)
            ->toBeInstanceOf(User::class)
            ->id->toBe($manager->id);
    });
});

describe('Check-in/out', function () {
    test('checkIn sets status and timestamp', function () {
        $assignment = ShiftAssignment::factory()->create();

        $assignment->checkIn();
        $assignment->refresh();

        expect($assignment->status)->toBe(ShiftAssignment::STATUS_CHECKED_IN)
            ->and($assignment->checked_in_at)->not->toBeNull();
    });

    test('checkOut sets status and timestamp', function () {
        $assignment = ShiftAssignment::factory()->checkedIn()->create();

        $assignment->checkOut();
        $assignment->refresh();

        expect($assignment->status)->toBe(ShiftAssignment::STATUS_CHECKED_OUT)
            ->and($assignment->checked_out_at)->not->toBeNull();
    });

    test('markNoShow sets status', function () {
        $assignment = ShiftAssignment::factory()->create();

        $assignment->markNoShow();
        $assignment->refresh();

        expect($assignment->status)->toBe(ShiftAssignment::STATUS_NO_SHOW);
    });

    test('checkBackIn reverts to checked_in, clears checked_out_at, and preserves checked_in_at', function () {
        $originalCheckedInAt = now()->subHours(2);
        $assignment = ShiftAssignment::factory()->checkedOut()->create([
            'checked_in_at' => $originalCheckedInAt,
        ]);

        $assignment->checkBackIn();
        $assignment->refresh();

        expect($assignment->status)->toBe(ShiftAssignment::STATUS_CHECKED_IN)
            ->and($assignment->checked_out_at)->toBeNull()
            ->and($assignment->checked_in_at->timestamp)->toBe($originalCheckedInAt->timestamp);
    });
});

describe('Confirmation & Bonus Sync', function () {
    test('confirm sets manager and timestamp', function () {
        $assignment = ShiftAssignment::factory()->create();
        $manager = User::factory()->create();

        $assignment->confirm($manager);
        $assignment->refresh();

        expect($assignment->confirmed_by_user_id)->toBe($manager->id)
            ->and($assignment->confirmed_at)->not->toBeNull();
    });

    test('confirm auto-awards EventBonus for auto-award role (PIO Table)', function () {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\EventTypeSeeder']);
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\BonusTypeSeeder']);

        $eventConfiguration = EventConfiguration::factory()->create();
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
            'name' => 'Public Information Table',
            'bonus_points' => 100,
            'requires_confirmation' => true,
        ]);
        $shift = Shift::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => $role->id,
        ]);
        $assignment = ShiftAssignment::factory()->create(['shift_id' => $shift->id]);
        $manager = User::factory()->create();

        $assignment->confirm($manager);

        $bonusType = BonusType::where('code', 'public_info_booth')->first();

        expect(EventBonus::where('event_configuration_id', $eventConfiguration->id)
            ->where('bonus_type_id', $bonusType->id)
            ->where('is_verified', true)
            ->exists()
        )->toBeTrue();
    });

    test('revokeConfirmation removes auto-awarded EventBonus', function () {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\EventTypeSeeder']);
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\BonusTypeSeeder']);

        $eventConfiguration = EventConfiguration::factory()->create();
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
            'name' => 'Public Information Table',
            'bonus_points' => 100,
            'requires_confirmation' => true,
        ]);
        $shift = Shift::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => $role->id,
        ]);
        $assignment = ShiftAssignment::factory()->create(['shift_id' => $shift->id]);
        $manager = User::factory()->create();

        $assignment->confirm($manager);

        $bonusType = BonusType::where('code', 'public_info_booth')->first();
        expect(EventBonus::where('event_configuration_id', $eventConfiguration->id)
            ->where('bonus_type_id', $bonusType->id)
            ->exists()
        )->toBeTrue();

        $assignment->revokeConfirmation();

        expect(EventBonus::where('event_configuration_id', $eventConfiguration->id)
            ->where('bonus_type_id', $bonusType->id)
            ->exists()
        )->toBeFalse();
    });

    test('confirm does NOT auto-award EventBonus for eligibility-only role (Safety Officer)', function () {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\EventTypeSeeder']);
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\BonusTypeSeeder']);

        $eventConfiguration = EventConfiguration::factory()->create();
        $role = ShiftRole::factory()->safetyOfficer()->create([
            'event_configuration_id' => $eventConfiguration->id,
        ]);
        $shift = Shift::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => $role->id,
        ]);
        $assignment = ShiftAssignment::factory()->create(['shift_id' => $shift->id]);
        $manager = User::factory()->create();

        $assignment->confirm($manager);

        expect(EventBonus::where('event_configuration_id', $eventConfiguration->id)->count())->toBe(0);
    });

    test('confirm does not create EventBonus for non-bonus role', function () {
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\EventTypeSeeder']);
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\BonusTypeSeeder']);

        $eventConfiguration = EventConfiguration::factory()->create();
        $role = ShiftRole::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
            'name' => 'Station Captain',
        ]);
        $shift = Shift::factory()->create([
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => $role->id,
        ]);
        $assignment = ShiftAssignment::factory()->create(['shift_id' => $shift->id]);
        $manager = User::factory()->create();

        $assignment->confirm($manager);

        expect(EventBonus::where('event_configuration_id', $eventConfiguration->id)->count())->toBe(0);
    });
});

describe('Scopes', function () {
    test('pendingConfirmation scope filters correctly', function () {
        $confirmed = ShiftAssignment::factory()->confirmed()->create();
        $pending = ShiftAssignment::factory()->create();
        $noShow = ShiftAssignment::factory()->noShow()->create();

        $results = ShiftAssignment::pendingConfirmation()->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($pending->id);
    });

    test('forUser scope filters by user id', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        ShiftAssignment::factory()->count(2)->create(['user_id' => $user->id]);
        ShiftAssignment::factory()->create(['user_id' => $otherUser->id]);

        $results = ShiftAssignment::forUser($user->id)->get();

        expect($results)->toHaveCount(2)
            ->each(fn ($assignment) => $assignment->user_id->toBe($user->id));
    });
});

describe('Uniqueness', function () {
    test('cannot assign same user to same shift twice', function () {
        $shift = Shift::factory()->withCapacity(5)->create();
        $user = User::factory()->create();

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
        ]);

        ShiftAssignment::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
        ]);
    })->throws(QueryException::class);
});
