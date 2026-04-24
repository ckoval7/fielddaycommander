<?php

use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\GuestbookEntry;
use App\Models\OperatingSession;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Station;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DemoSeeder::class);
});

test('seeder creates 14 users', function () {
    expect(User::count())->toBe(14);
});

test('seeder creates users with realistic callsigns', function () {
    User::all()->each(function (User $user) {
        expect($user->call_sign)->toMatch(
            '/^(W|K|N|AA|AB|AC|AD|AE|AF|AG|AH|AI|AJ|AK|AL|[KNW][A-Z]|VE[123456789]|VA[234567]|VY[12])\d[A-Z]{1,3}$/'
        );
    });
});

test('seeder creates one active event in progress', function () {
    $event = Event::where('is_active', true)->first();
    expect($event)->not->toBeNull();
    expect($event->start_time->isPast())->toBeTrue();
    expect($event->end_time->isFuture())->toBeTrue();
});

test('seeder creates 6 stations with correct types', function () {
    expect(Station::count())->toBe(6);
    expect(Station::where('is_gota', false)->where('is_vhf_only', false)->count())->toBe(4); // 4A HF stations
    expect(Station::where('is_gota', true)->count())->toBe(1);
    expect(Station::where('is_vhf_only', true)->count())->toBe(1);
});

test('seeder creates exactly 3 open operating sessions (GOTA left for user)', function () {
    expect(OperatingSession::whereNull('end_time')->count())->toBe(3);
});

test('seeder creates a realistic early-event contact count', function () {
    // 4 HF stations × 20–30 hist + VHF 4–8 + GOTA 5–10 + active sessions ≈ 94–172
    expect(Contact::count())->toBeGreaterThanOrEqual(94)->toBeLessThan(175);
});

test('seeder creates contacts only on the band and mode of their operating session', function () {
    Contact::with('operatingSession')->each(function (Contact $contact) {
        expect($contact->band_id)->toBe($contact->operatingSession->band_id);
        expect($contact->mode_id)->toBe($contact->operatingSession->mode_id);
    });
});

test('seeder creates guestbook entries', function () {
    expect(GuestbookEntry::count())->toBeGreaterThanOrEqual(6);
});

test('seeder stores demo_provisioned_at in system_config', function () {
    $value = DB::table('system_config')
        ->where('key', 'demo_provisioned_at')
        ->value('value');
    expect($value)->not->toBeNull();
    expect(Carbon::parse($value)->isToday())->toBeTrue();
});

test('seeder creates FD bonus types for the event resolved rules version', function () {
    $event = Event::where('is_active', true)->firstOrFail();
    $resolved = $event->resolved_rules_version;

    $matchingTypes = BonusType::where('event_type_id', $event->event_type_id)
        ->where('rules_version', $resolved)
        ->where('is_active', true)
        ->count();

    expect($matchingTypes)->toBeGreaterThan(0);

    $claimed = EventBonus::where('event_configuration_id', $event->eventConfiguration->id)->get();
    expect($claimed)->not->toBeEmpty();
    $claimed->each(function (EventBonus $bonus) use ($resolved): void {
        expect($bonus->bonusType->rules_version)->toBe($resolved);
    });
});

test('seeder does not produce claimed-but-unverified bonuses with points', function () {
    $event = Event::where('is_active', true)->firstOrFail();

    $invalid = EventBonus::where('event_configuration_id', $event->eventConfiguration->id)
        ->where('is_verified', false)
        ->where('calculated_points', '>', 0)
        ->get();

    expect($invalid)->toBeEmpty();
});

test('seeder does not pre-earn the W1AW bulletin bonus', function () {
    $event = Event::where('is_active', true)->firstOrFail();

    $w1awBonus = EventBonus::where('event_configuration_id', $event->eventConfiguration->id)
        ->whereHas('bonusType', fn ($q) => $q->where('code', 'w1aw_bulletin'))
        ->first();

    expect($w1awBonus)->toBeNull();
});

test('seeder creates organization and sets default_organization_id', function () {
    $org = Organization::active()->first();
    expect($org)->not->toBeNull();
    expect($org->name)->toBe('Demo Radio Club');
    expect($org->callsign)->toBe('W1FDC');
    expect($org->email)->toBe('info@demoradioclub.example');
    expect($org->phone)->not->toBeNull();
    expect($org->address)->not->toBeNull();

    $settingId = Setting::get('default_organization_id');
    expect((int) $settingId)->toBe($org->id);
});
