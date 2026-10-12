<?php

use App\Http\Middleware\DevRoleOverride;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

/**
 * Run the middleware directly and return the pass-through response body.
 */
function runDevRoleOverride(): string
{
    return (new DevRoleOverride)->handle(Request::create('/'), fn () => response('passed through'))->getContent();
}

beforeEach(function () {
    $this->registrar = $this->spy(PermissionRegistrar::class);
});

test('clears the permission cache when developer mode is on and an override is active', function () {
    config(['developer.enabled' => true]);
    $this->actingAs(User::factory()->create());
    session(['dev_role_override' => 'Operator']);

    expect(runDevRoleOverride())->toBe('passed through');

    $this->registrar->shouldHaveReceived('forgetCachedPermissions')->once();
});

test('does not clear the permission cache when developer mode is off', function () {
    config(['developer.enabled' => false]);
    $this->actingAs(User::factory()->create());
    session(['dev_role_override' => 'System Administrator']);

    expect(runDevRoleOverride())->toBe('passed through');

    $this->registrar->shouldNotHaveReceived('forgetCachedPermissions');
});

test('does not clear the permission cache for guests', function () {
    config(['developer.enabled' => true]);
    session(['dev_role_override' => 'System Administrator']);

    expect(runDevRoleOverride())->toBe('passed through');

    $this->registrar->shouldNotHaveReceived('forgetCachedPermissions');
});

test('does not clear the permission cache without an override in the session', function () {
    config(['developer.enabled' => true]);
    $this->actingAs(User::factory()->create());

    expect(runDevRoleOverride())->toBe('passed through');

    $this->registrar->shouldNotHaveReceived('forgetCachedPermissions');
});
