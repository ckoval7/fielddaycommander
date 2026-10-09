<?php

use App\Models\Band;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\BandSeeder;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\ModeSeeder;
use Database\Seeders\OperatingClassSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SectionSeeder;
use Database\Seeders\SystemAdminSeeder;
use Illuminate\Support\Facades\Hash;

uses()->group('unit', 'seeders');

/**
 * The production seeders in the order deploy.sh and docker/entrypoint.sh run them.
 *
 * @var array<int, class-string>
 */
const PRODUCTION_SEEDERS = [
    EventTypeSeeder::class,
    BandSeeder::class,
    ModeSeeder::class,
    SectionSeeder::class,
    OperatingClassSeeder::class,
    BonusTypeSeeder::class,
    PermissionSeeder::class,
    RoleSeeder::class,
    SystemAdminSeeder::class,
];

test('production seeders can be re-run without errors or duplicate rows', function () {
    $this->seed(PRODUCTION_SEEDERS);

    $counts = fn () => [
        'event_types' => EventType::count(),
        'bands' => Band::count(),
        'modes' => Mode::count(),
        'sections' => Section::count(),
        'operating_classes' => OperatingClass::count(),
        'system_users' => User::where('call_sign', User::SYSTEM_CALL_SIGN)->count(),
    ];

    $firstRun = $counts();

    $this->seed(PRODUCTION_SEEDERS);

    expect($counts())->toEqual($firstRun)
        ->and($firstRun['system_users'])->toBe(1);
});

test('re-running the system admin seeder keeps the password set in the setup wizard', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, SystemAdminSeeder::class]);

    $admin = User::where('call_sign', User::SYSTEM_CALL_SIGN)->sole();
    $admin->update(['password' => Hash::make('WizardChosen123!')]);

    $this->seed(SystemAdminSeeder::class);

    $rerunAdmin = User::where('call_sign', User::SYSTEM_CALL_SIGN)->sole();

    expect($rerunAdmin->id)->toBe($admin->id)
        ->and(Hash::check('WizardChosen123!', $rerunAdmin->password))->toBeTrue()
        ->and($rerunAdmin->hasRole('Config Only'))->toBeTrue();
});

test('system admin seeder does not duplicate a soft-deleted SYSTEM account', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, SystemAdminSeeder::class]);

    User::where('call_sign', User::SYSTEM_CALL_SIGN)->sole()->delete();

    $this->seed(SystemAdminSeeder::class);

    expect(User::withTrashed()->where('call_sign', User::SYSTEM_CALL_SIGN)->count())->toBe(1);
});
