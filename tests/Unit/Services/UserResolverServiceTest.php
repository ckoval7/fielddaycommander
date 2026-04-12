<?php

use App\Models\User;
use App\Services\UserResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->resolver = new UserResolverService;
});

test('returns existing user when callsign matches', function () {
    $user = User::factory()->create(['call_sign' => 'K3CPK']);

    $result = $this->resolver->resolveOrCreate('K3CPK');

    expect($result->id)->toBe($user->id);
});

test('matches callsign case-insensitively', function () {
    $user = User::factory()->create(['call_sign' => 'K3CPK']);

    $result = $this->resolver->resolveOrCreate('k3cpk');

    expect($result->id)->toBe($user->id);
});

test('creates locked stub when callsign is unknown', function () {
    $result = $this->resolver->resolveOrCreate('W1AW');

    expect($result)->toBeInstanceOf(User::class)
        ->and($result->exists)->toBeTrue()
        ->and($result->call_sign)->toBe('W1AW')
        ->and($result->first_name)->toBe('W1AW')
        ->and($result->last_name)->toBe('(Imported)')
        ->and($result->email)->toBe('w1aw@imported.local')
        ->and($result->user_role)->toBe('locked')
        ->and($result->account_locked_at)->not->toBeNull()
        ->and($result->requires_password_change)->toBeTrue();
});

test('stub callsign is uppercased', function () {
    $result = $this->resolver->resolveOrCreate('w1aw');

    expect($result->call_sign)->toBe('W1AW')
        ->and($result->email)->toBe('w1aw@imported.local');
});

test('is idempotent on repeated calls', function () {
    $first = $this->resolver->resolveOrCreate('W1AW');
    $second = $this->resolver->resolveOrCreate('W1AW');

    expect($second->id)->toBe($first->id)
        ->and(User::where('call_sign', 'W1AW')->count())->toBe(1);
});
