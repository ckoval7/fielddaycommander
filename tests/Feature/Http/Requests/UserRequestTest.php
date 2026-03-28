<?php

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Permission::findOrCreate('manage-users', 'web');

    $this->adminRole = Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);
    $this->adminRole->givePermissionTo('manage-users');
    $this->operatorRole = Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('System Administrator');

    $this->unprivilegedUser = User::factory()->create();
});

// =============================================================================
// StoreUserRequest - Authorization
// =============================================================================

describe('StoreUserRequest authorization', function () {
    test('authorizes users with manage-users permission', function () {
        $request = new StoreUserRequest;
        $request->setUserResolver(fn () => $this->admin);

        expect($request->authorize())->toBeTrue();
    });

    test('denies users without manage-users permission', function () {
        $request = new StoreUserRequest;
        $request->setUserResolver(fn () => $this->unprivilegedUser);

        expect($request->authorize())->toBeFalse();
    });

    test('denies unauthenticated requests', function () {
        $request = new StoreUserRequest;
        $request->setUserResolver(fn () => null);

        expect($request->authorize())->toBeFalse();
    });
});

// =============================================================================
// StoreUserRequest - call_sign uppercase normalization
// =============================================================================

describe('StoreUserRequest call_sign normalization', function () {
    test('normalizes call_sign to uppercase before validation', function () {
        $request = StoreUserRequest::create('/', 'POST', ['call_sign' => 'w1aw']);
        $request->setUserResolver(fn () => $this->admin);

        $reflection = new ReflectionMethod($request, 'prepareForValidation');
        $reflection->setAccessible(true);
        $reflection->invoke($request);

        expect($request->call_sign)->toBe('W1AW');
    });

    test('handles null call_sign gracefully during normalization', function () {
        $request = StoreUserRequest::create('/', 'POST', []);
        $request->setUserResolver(fn () => $this->admin);

        $reflection = new ReflectionMethod($request, 'prepareForValidation');
        $reflection->setAccessible(true);
        $reflection->invoke($request);

        expect($request->call_sign)->toBe('');
    });
});

// =============================================================================
// StoreUserRequest - Validation Rules
// =============================================================================

describe('StoreUserRequest validation', function () {
    function validStoreData(int $roleId): array
    {
        return [
            'call_sign' => 'W1AW',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'role_id' => $roleId,
            'invite_mode' => true,
        ];
    }

    function storeRules(): array
    {
        return (new StoreUserRequest)->rules();
    }

    test('passes with valid data in invite mode', function () {
        $validator = Validator::make(validStoreData($this->operatorRole->id), storeRules());

        expect($validator->passes())->toBeTrue();
    });

    test('requires call_sign', function () {
        $data = array_merge(validStoreData($this->operatorRole->id), ['call_sign' => '']);

        $validator = Validator::make($data, storeRules());

        expect($validator->errors()->has('call_sign'))->toBeTrue();
    });

    test('enforces call_sign regex - only uppercase letters, numbers, and slashes', function () {
        foreach (['W1AW!', 'W 1AW', 'w1aw', 'call@sign'] as $invalidCallSign) {
            $data = array_merge(validStoreData($this->operatorRole->id), ['call_sign' => $invalidCallSign]);
            $validator = Validator::make($data, storeRules());
            expect($validator->errors()->has('call_sign'))->toBeTrue("Expected {$invalidCallSign} to fail");
        }
    });

    test('accepts call_sign with slash', function () {
        $data = array_merge(validStoreData($this->operatorRole->id), ['call_sign' => 'W1AW/P']);

        $validator = Validator::make($data, storeRules());

        expect($validator->errors()->has('call_sign'))->toBeFalse();
    });

    test('enforces call_sign uniqueness', function () {
        User::factory()->create(['call_sign' => 'W1AW']);

        $validator = Validator::make(validStoreData($this->operatorRole->id), storeRules());

        expect($validator->errors()->has('call_sign'))->toBeTrue();
    });

    test('requires first_name', function () {
        $data = array_merge(validStoreData($this->operatorRole->id), ['first_name' => '']);

        $validator = Validator::make($data, storeRules());

        expect($validator->errors()->has('first_name'))->toBeTrue();
    });

    test('requires last_name', function () {
        $data = array_merge(validStoreData($this->operatorRole->id), ['last_name' => '']);

        $validator = Validator::make($data, storeRules());

        expect($validator->errors()->has('last_name'))->toBeTrue();
    });

    test('requires email', function () {
        $data = array_merge(validStoreData($this->operatorRole->id), ['email' => '']);

        $validator = Validator::make($data, storeRules());

        expect($validator->errors()->has('email'))->toBeTrue();
    });

    test('requires valid email format', function () {
        $data = array_merge(validStoreData($this->operatorRole->id), ['email' => 'not-an-email']);

        $validator = Validator::make($data, storeRules());

        expect($validator->errors()->has('email'))->toBeTrue();
    });

    test('enforces email uniqueness', function () {
        User::factory()->create(['email' => 'john@example.com']);

        $validator = Validator::make(validStoreData($this->operatorRole->id), storeRules());

        expect($validator->errors()->has('email'))->toBeTrue();
    });

    test('accepts valid license classes', function () {
        foreach (['Technician', 'General', 'Advanced', 'Extra'] as $class) {
            $data = array_merge(validStoreData($this->operatorRole->id), ['license_class' => $class]);
            $validator = Validator::make($data, storeRules());
            expect($validator->errors()->has('license_class'))->toBeFalse("Expected {$class} to be valid");
        }
    });

    test('rejects invalid license class', function () {
        $data = array_merge(validStoreData($this->operatorRole->id), ['license_class' => 'Amateur']);

        $validator = Validator::make($data, storeRules());

        expect($validator->errors()->has('license_class'))->toBeTrue();
    });

    test('license_class is optional', function () {
        $data = array_merge(validStoreData($this->operatorRole->id), ['license_class' => null]);

        $validator = Validator::make($data, storeRules());

        expect($validator->errors()->has('license_class'))->toBeFalse();
    });

    test('requires role_id', function () {
        $data = array_merge(validStoreData($this->operatorRole->id), ['role_id' => null]);

        $validator = Validator::make($data, storeRules());

        expect($validator->errors()->has('role_id'))->toBeTrue();
    });

    test('rejects non-existent role_id', function () {
        $validator = Validator::make(validStoreData(99999), storeRules());

        expect($validator->errors()->has('role_id'))->toBeTrue();
    });

    test('requires invite_mode', function () {
        $data = validStoreData($this->operatorRole->id);
        unset($data['invite_mode']);

        $validator = Validator::make($data, storeRules());

        expect($validator->errors()->has('invite_mode'))->toBeTrue();
    });

    test('password is required when invite_mode is false', function () {
        $data = array_merge(validStoreData($this->operatorRole->id), [
            'invite_mode' => false,
            'password' => '',
        ]);

        $validator = Validator::make($data, storeRules());

        expect($validator->errors()->has('password'))->toBeTrue();
    });

    test('password is not required when invite_mode is true', function () {
        $data = validStoreData($this->operatorRole->id);

        $validator = Validator::make($data, storeRules());

        expect($validator->errors()->has('password'))->toBeFalse();
    });

    test('password must be confirmed when provided', function () {
        $data = array_merge(validStoreData($this->operatorRole->id), [
            'invite_mode' => false,
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'DifferentPassword123!',
        ]);

        $validator = Validator::make($data, storeRules());

        expect($validator->errors()->has('password'))->toBeTrue();
    });

    test('passes with password when invite_mode is false', function () {
        $data = array_merge(validStoreData($this->operatorRole->id), [
            'invite_mode' => false,
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $validator = Validator::make($data, storeRules());

        expect($validator->passes())->toBeTrue();
    });
});

// =============================================================================
// UpdateUserRequest - Authorization
// =============================================================================

describe('UpdateUserRequest authorization', function () {
    test('authorizes users with manage-users permission', function () {
        $request = new UpdateUserRequest;
        $request->setUserResolver(fn () => $this->admin);

        expect($request->authorize())->toBeTrue();
    });

    test('denies users without manage-users permission', function () {
        $request = new UpdateUserRequest;
        $request->setUserResolver(fn () => $this->unprivilegedUser);

        expect($request->authorize())->toBeFalse();
    });

    test('denies unauthenticated requests', function () {
        $request = new UpdateUserRequest;
        $request->setUserResolver(fn () => null);

        expect($request->authorize())->toBeFalse();
    });
});

// =============================================================================
// UpdateUserRequest - call_sign uppercase normalization
// =============================================================================

describe('UpdateUserRequest call_sign normalization', function () {
    test('normalizes call_sign to uppercase before validation', function () {
        $request = UpdateUserRequest::create('/', 'PUT', ['call_sign' => 'k2xyz']);
        $request->setUserResolver(fn () => $this->admin);

        $reflection = new ReflectionMethod($request, 'prepareForValidation');
        $reflection->setAccessible(true);
        $reflection->invoke($request);

        expect($request->call_sign)->toBe('K2XYZ');
    });

    test('handles null call_sign gracefully during normalization', function () {
        $request = UpdateUserRequest::create('/', 'PUT', []);
        $request->setUserResolver(fn () => $this->admin);

        $reflection = new ReflectionMethod($request, 'prepareForValidation');
        $reflection->setAccessible(true);
        $reflection->invoke($request);

        expect($request->call_sign)->toBe('');
    });
});

// =============================================================================
// UpdateUserRequest - Validation Rules
// =============================================================================

describe('UpdateUserRequest validation', function () {
    function validUpdateData(int $roleId): array
    {
        return [
            'call_sign' => 'W1AW',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'role_id' => $roleId,
        ];
    }

    function updateRulesForUser(User $user): array
    {
        $request = UpdateUserRequest::create('/', 'PUT', []);
        $request->setRouteResolver(function () use ($user) {
            $route = new \Illuminate\Routing\Route(['PUT'], '/', []);
            $route->bind(new \Illuminate\Http\Request);
            $route->setParameter('user', $user);

            return $route;
        });

        return $request->rules();
    }

    test('passes with valid data', function () {
        $user = User::factory()->create(['call_sign' => 'W1AW', 'email' => 'john@example.com']);

        $validator = Validator::make(validUpdateData($this->operatorRole->id), updateRulesForUser($user));

        expect($validator->passes())->toBeTrue();
    });

    test('requires call_sign', function () {
        $user = User::factory()->create();
        $data = array_merge(validUpdateData($this->operatorRole->id), ['call_sign' => '']);

        $validator = Validator::make($data, updateRulesForUser($user));

        expect($validator->errors()->has('call_sign'))->toBeTrue();
    });

    test('enforces call_sign regex', function () {
        $user = User::factory()->create();
        $data = array_merge(validUpdateData($this->operatorRole->id), ['call_sign' => 'invalid call!']);

        $validator = Validator::make($data, updateRulesForUser($user));

        expect($validator->errors()->has('call_sign'))->toBeTrue();
    });

    test('allows updating with own call_sign', function () {
        $user = User::factory()->create(['call_sign' => 'W1AW', 'email' => 'john@example.com']);

        $data = validUpdateData($this->operatorRole->id);
        $validator = Validator::make($data, updateRulesForUser($user));

        expect($validator->passes())->toBeTrue();
    });

    test('rejects call_sign already taken by another user', function () {
        User::factory()->create(['call_sign' => 'W1AW']);
        $userBeingEdited = User::factory()->create(['call_sign' => 'K2XYZ']);

        $data = array_merge(validUpdateData($this->operatorRole->id), ['call_sign' => 'W1AW', 'email' => $userBeingEdited->email]);
        $validator = Validator::make($data, updateRulesForUser($userBeingEdited));

        expect($validator->errors()->has('call_sign'))->toBeTrue();
    });

    test('requires first_name', function () {
        $user = User::factory()->create();
        $data = array_merge(validUpdateData($this->operatorRole->id), ['first_name' => '']);

        $validator = Validator::make($data, updateRulesForUser($user));

        expect($validator->errors()->has('first_name'))->toBeTrue();
    });

    test('requires last_name', function () {
        $user = User::factory()->create();
        $data = array_merge(validUpdateData($this->operatorRole->id), ['last_name' => '']);

        $validator = Validator::make($data, updateRulesForUser($user));

        expect($validator->errors()->has('last_name'))->toBeTrue();
    });

    test('requires email', function () {
        $user = User::factory()->create();
        $data = array_merge(validUpdateData($this->operatorRole->id), ['email' => '']);

        $validator = Validator::make($data, updateRulesForUser($user));

        expect($validator->errors()->has('email'))->toBeTrue();
    });

    test('requires valid email format', function () {
        $user = User::factory()->create();
        $data = array_merge(validUpdateData($this->operatorRole->id), ['email' => 'not-an-email']);

        $validator = Validator::make($data, updateRulesForUser($user));

        expect($validator->errors()->has('email'))->toBeTrue();
    });

    test('allows updating with own email', function () {
        $user = User::factory()->create(['call_sign' => 'W1AW', 'email' => 'john@example.com']);

        $data = validUpdateData($this->operatorRole->id);
        $validator = Validator::make($data, updateRulesForUser($user));

        expect($validator->passes())->toBeTrue();
    });

    test('rejects email already taken by another user', function () {
        User::factory()->create(['email' => 'john@example.com']);
        $userBeingEdited = User::factory()->create(['call_sign' => 'W2XYZ', 'email' => 'other@example.com']);

        $data = array_merge(validUpdateData($this->operatorRole->id), ['call_sign' => 'W2XYZ', 'email' => 'john@example.com']);
        $validator = Validator::make($data, updateRulesForUser($userBeingEdited));

        expect($validator->errors()->has('email'))->toBeTrue();
    });

    test('accepts valid license classes', function () {
        $user = User::factory()->create(['call_sign' => 'W1AW', 'email' => 'john@example.com']);

        foreach (['Technician', 'General', 'Advanced', 'Extra'] as $class) {
            $data = array_merge(validUpdateData($this->operatorRole->id), ['license_class' => $class]);
            $validator = Validator::make($data, updateRulesForUser($user));
            expect($validator->errors()->has('license_class'))->toBeFalse("Expected {$class} to be valid");
        }
    });

    test('rejects invalid license class', function () {
        $user = User::factory()->create();
        $data = array_merge(validUpdateData($this->operatorRole->id), ['license_class' => 'Amateur']);

        $validator = Validator::make($data, updateRulesForUser($user));

        expect($validator->errors()->has('license_class'))->toBeTrue();
    });

    test('license_class is optional', function () {
        $user = User::factory()->create(['call_sign' => 'W1AW', 'email' => 'john@example.com']);
        $data = array_merge(validUpdateData($this->operatorRole->id), ['license_class' => null]);

        $validator = Validator::make($data, updateRulesForUser($user));

        expect($validator->errors()->has('license_class'))->toBeFalse();
    });

    test('requires role_id', function () {
        $user = User::factory()->create();
        $data = array_merge(validUpdateData($this->operatorRole->id), ['role_id' => null]);

        $validator = Validator::make($data, updateRulesForUser($user));

        expect($validator->errors()->has('role_id'))->toBeTrue();
    });

    test('rejects non-existent role_id', function () {
        $user = User::factory()->create();

        $validator = Validator::make(validUpdateData(99999), updateRulesForUser($user));

        expect($validator->errors()->has('role_id'))->toBeTrue();
    });

    test('does not include password or invite_mode fields', function () {
        $user = User::factory()->create();
        $rules = updateRulesForUser($user);

        expect($rules)->not->toHaveKey('password');
        expect($rules)->not->toHaveKey('invite_mode');
    });
});
