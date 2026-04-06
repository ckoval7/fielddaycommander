<?php

use App\Models\Contact;
use App\Models\Event;
use App\Models\GuestbookEntry;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\DemoSeeder::class);
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

test('seeder creates exactly 4 open operating sessions', function () {
    expect(OperatingSession::whereNull('end_time')->count())->toBe(4);
});

test('seeder creates a realistic early-event contact count', function () {
    // 4 HF stations × 20–30 hist + VHF 4–8 + GOTA 5–10 + active sessions ≈ 99–165
    expect(Contact::count())->toBeGreaterThanOrEqual(95)->toBeLessThan(175);
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
    $value = \Illuminate\Support\Facades\DB::table('system_config')
        ->where('key', 'demo_provisioned_at')
        ->value('value');
    expect($value)->not->toBeNull();
    expect(\Carbon\Carbon::parse($value)->isToday())->toBeTrue();
});
