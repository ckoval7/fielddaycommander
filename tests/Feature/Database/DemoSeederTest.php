<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\MessageRole;
use App\Models\BonusType;
use App\Models\BulletinScheduleEntry;
use App\Models\Contact;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\GuestbookEntry;
use App\Models\Message;
use App\Models\OperatingSession;
use App\Models\Organization;
use App\Models\SafetyChecklistEntry;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
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
    // 4 HF stations at 10–16 QSOs/hour over the 2–3 hours since the event
    // started, plus a short VHF run (4–8) and a GOTA run (5–10)
    expect(Contact::count())->toBeGreaterThanOrEqual(70)->toBeLessThan(220);
});

test('seeder stamps contacts as logged when they were made', function () {
    Contact::all()->each(function (Contact $contact) {
        expect($contact->created_at->equalTo($contact->qso_time))->toBeTrue();
    });
});

test('seeder hands each station over without overlapping sessions', function () {
    $stationsWithHandoffs = 0;

    OperatingSession::all()->groupBy('station_id')->each(function ($sessions) use (&$stationsWithHandoffs) {
        $ordered = $sessions->sortBy('start_time')->values();

        for ($i = 1; $i < $ordered->count(); $i++) {
            expect($ordered[$i - 1]->end_time)->not->toBeNull();
            expect($ordered[$i - 1]->end_time->lte($ordered[$i]->start_time))->toBeTrue();
            expect($ordered[$i]->operator_user_id)->not->toBe($ordered[$i - 1]->operator_user_id);
            $stationsWithHandoffs++;
        }
    });

    expect($stationsWithHandoffs)->toBeGreaterThan(0);
    expect(OperatingSession::whereNull('end_time')->get()->pluck('operator_user_id')->duplicates())->toBeEmpty();
});

test('seeder staffs HF stations from the operator shift on duty', function () {
    $operatorRole = ShiftRole::where('name', 'Operator')->firstOrFail();

    OperatingSession::with('station')->get()
        ->filter(fn (OperatingSession $session) => ! $session->station->is_gota && ! $session->station->is_vhf_only)
        ->each(function (OperatingSession $session) use ($operatorRole) {
            $assignment = ShiftAssignment::where('user_id', $session->operator_user_id)
                ->whereHas('shift', fn ($q) => $q->where('shift_role_id', $operatorRole->id)
                    ->where('start_time', '<=', $session->start_time->copy()->addMinutes(15))
                    ->where('end_time', '>=', $session->start_time))
                ->first();

            expect($assignment)->not->toBeNull();
        });
});

test('seeder staggers HF sessions instead of starting them together', function () {
    $starts = OperatingSession::whereNull('end_time')->pluck('start_time')->map->timestamp;

    expect(OperatingSession::count())->toBeGreaterThan(Station::count());
    expect(OperatingSession::pluck('start_time')->map->timestamp->unique()->count())->toBeGreaterThan(2);
    expect($starts->every(fn (int $start) => $start <= now()->timestamp))->toBeTrue();
});

test('seeder spreads contact times across each operating session window', function () {
    OperatingSession::with('contacts')->get()->each(function (OperatingSession $session) {
        $times = $session->contacts->pluck('qso_time');
        $windowEnd = $session->end_time ?? now();

        $times->each(fn (Carbon $time) => expect($time->betweenIncluded($session->start_time, $windowEnd))->toBeTrue());

        if ($times->count() >= 3) {
            expect($times->map->timestamp->unique()->count())->toBeGreaterThan(1);
            expect($times->max()->diffInMinutes($times->min(), true))->toBeGreaterThan(1);
        }
    });
});

test('seeder starts the event on the hour so the schedule lands on clean boundaries', function () {
    $event = Event::where('is_active', true)->first();

    expect($event->start_time->minute)->toBe(0);
    expect($event->start_time->second)->toBe(0);
    expect($event->start_time->diffInHours($event->end_time))->toEqual(24.0);

    Shift::all()->each(function (Shift $shift) {
        expect($shift->start_time->minute)->toBe(0);
        expect($shift->end_time->minute)->toBe(0);
    });
});

test('seeder sets shift attendance by where each shift sits in time', function () {
    ShiftAssignment::with('shift')->get()->each(function (ShiftAssignment $assignment) {
        $shift = $assignment->shift;

        if ($shift->end_time->isPast()) {
            expect($assignment->status)->toBe(ShiftAssignment::STATUS_CHECKED_OUT);
            expect($assignment->checked_out_at)->not->toBeNull();
            expect($assignment->checked_out_at->gt($assignment->checked_in_at))->toBeTrue();
        } elseif ($shift->start_time->isPast()) {
            expect($assignment->status)->toBe(ShiftAssignment::STATUS_CHECKED_IN);
            expect($assignment->checked_out_at)->toBeNull();
        } else {
            expect($assignment->status)->toBe(ShiftAssignment::STATUS_SCHEDULED);
            expect($assignment->checked_in_at)->toBeNull();

            return;
        }

        expect(abs($assignment->checked_in_at->diffInMinutes($shift->start_time)))->toBeLessThanOrEqual(10);
        expect($assignment->checked_in_at->isFuture())->toBeFalse();
    });

    expect(ShiftAssignment::where('status', ShiftAssignment::STATUS_CHECKED_OUT)->exists())->toBeTrue();
    expect(ShiftAssignment::where('status', ShiftAssignment::STATUS_CHECKED_IN)->exists())->toBeTrue();
    expect(ShiftAssignment::where('status', ShiftAssignment::STATUS_SCHEDULED)->exists())->toBeTrue();
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

test('seeder spreads guestbook signings across the event and verifies them afterwards', function () {
    $event = Event::where('is_active', true)->first();
    $entries = GuestbookEntry::orderBy('created_at')->orderBy('id')->get();
    $pending = $entries->pop();

    expect($pending->is_verified)->toBeFalse();
    expect($pending->verified_at)->toBeNull();
    expect($pending->verified_by)->toBeNull();
    expect($pending->created_at->betweenIncluded($event->start_time, now()))->toBeTrue();

    $entries->each(function (GuestbookEntry $entry) use ($event) {
        expect($entry->is_verified)->toBeTrue();
        expect($entry->created_at->betweenIncluded($event->start_time, now()))->toBeTrue();
        expect($entry->verified_at->gte($entry->created_at))->toBeTrue();
        expect($entry->verified_at->isFuture())->toBeFalse();
        expect($entry->verified_by)->not->toBeNull();
    });

    expect($entries->map->created_at->map->timestamp->unique()->count())->toBeGreaterThan(1);
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

test('seeder claims setup bonuses before the event and verifies the media publicity claim once on the air', function () {
    $event = Event::where('is_active', true)->firstOrFail();

    EventBonus::with('bonusType')->get()->each(function (EventBonus $bonus) use ($event) {
        if (in_array($bonus->bonusType->code, ['sm_sec_message', 'nts_message'], true)) {
            expect($bonus->created_at->gt($event->start_time))->toBeTrue();
            expect($bonus->created_at->isFuture())->toBeFalse();

            return;
        }

        if ($bonus->bonusType->code === 'media_publicity') {
            expect($bonus->is_verified)->toBeTrue();
            expect($bonus->calculated_points)->toBe($bonus->bonusType->base_points);
            expect($bonus->created_at->gt($event->start_time))->toBeTrue();
            expect($bonus->created_at->isFuture())->toBeFalse();

            return;
        }

        expect($bonus->created_at->lt($event->start_time))->toBeTrue();
        expect($bonus->verified_at->gt($bonus->created_at))->toBeTrue();
    });
});

test('seeder completes safety checklist items during setup', function () {
    $event = Event::where('is_active', true)->firstOrFail();
    $completed = SafetyChecklistEntry::where('is_completed', true)->get();

    expect($completed)->not->toBeEmpty();
    $completed->each(fn (SafetyChecklistEntry $entry) => expect($entry->completed_at->lt($event->start_time))->toBeTrue());
});

test('seeder commits equipment ahead of time and delivers it during setup', function () {
    $event = Event::where('is_active', true)->firstOrFail();
    $equipmentEvents = EquipmentEvent::all();

    $equipmentEvents->each(function (EquipmentEvent $equipmentEvent) use ($event) {
        expect($equipmentEvent->committed_at->lt($event->start_time->copy()->subDays(2)))->toBeTrue();
        expect($equipmentEvent->status_changed_at->lte($event->start_time))->toBeTrue();
        expect($equipmentEvent->status_changed_at->gte($equipmentEvent->committed_at))->toBeTrue();
    });

    expect($equipmentEvents->map->status_changed_at->map->timestamp->unique()->count())->toBeGreaterThan(1);
});

test('seeder backdates the club, its members and the event plan', function () {
    $event = Event::where('is_active', true)->firstOrFail();

    User::all()->each(function (User $user) {
        expect($user->created_at->lt(now()->subDays(30)))->toBeTrue();
        expect($user->email_verified_at->gte($user->created_at))->toBeTrue();
    });

    expect(Organization::first()->created_at->lt(now()->subYear()))->toBeTrue();
    expect($event->created_at->lt($event->start_time->copy()->subWeeks(4)))->toBeTrue();
    expect($event->eventConfiguration->created_at->gte($event->created_at))->toBeTrue();
});

test('seeder replays a notification feed spread over the event', function () {
    $event = Event::where('is_active', true)->firstOrFail();
    $user = User::first();
    $notifications = $user->notifications()->get();

    expect($notifications)->not->toBeEmpty();
    expect($notifications->pluck('data.category')->unique())->toContain('station_status', 'new_section', 'guestbook');
    expect($notifications->map->created_at->map->timestamp->unique()->count())->toBeGreaterThan(5);

    $notifications->each(function ($notification) use ($event) {
        expect($notification->created_at->gte($event->start_time))->toBeTrue();
        expect($notification->created_at->isFuture())->toBeFalse();

        if ($notification->updated_at->lt(now()->subMinutes(30))) {
            expect($notification->read_at)->not->toBeNull();
        }
    });

    expect($user->unreadNotifications()->count())->toBeLessThan($notifications->count());
});

test('seeder staffs site setup before the event with a safety officer on watch', function () {
    $event = Event::where('is_active', true)->firstOrFail();
    $setupShifts = Shift::whereHas('shiftRole', fn ($q) => $q->where('name', 'Site Setup'))->with('assignments')->get();

    expect($setupShifts)->toHaveCount(2);
    $setupShifts->each(function (Shift $shift) use ($event) {
        expect($shift->end_time->lte($event->start_time))->toBeTrue();
        expect($shift->assignments)->not->toBeEmpty();
    });

    $setupStart = $setupShifts->min('start_time');
    $safetyWatch = Shift::whereHas('shiftRole', fn ($q) => $q->where('name', 'Safety Officer'))
        ->where('start_time', '<=', $setupStart)
        ->where('end_time', '>=', $event->start_time)
        ->withCount('assignments')
        ->first();

    expect($safetyWatch)->not->toBeNull();
    expect($safetyWatch->assignments_count)->toBe(1);
});

test('seeder originates the Section Manager message and a visitor message', function () {
    $event = Event::where('is_active', true)->firstOrFail();
    $messages = Message::all();

    expect($messages)->toHaveCount(2);
    expect($messages->where('is_sm_message', true))->toHaveCount(1);
    $messages->each(function (Message $message) use ($event) {
        expect($message->role)->toBe(MessageRole::Originated);
        expect($message->check)->toBe((string) count(explode(' ', $message->message_text)));
        expect($message->sent_at->gt($message->filed_at))->toBeTrue();
        expect($message->filed_at->gt($event->start_time))->toBeTrue();
        expect($message->sent_at->isFuture())->toBeFalse();
    });

    $earned = EventBonus::with('bonusType')->get()->pluck('bonusType.code');
    expect($earned)->toContain('sm_sec_message', 'nts_message');
});

test('seeder fills in the public visitor details', function () {
    $config = Event::where('is_active', true)->firstOrFail()->eventConfiguration;

    expect($config->is_open_to_public)->toBeTrue();
    expect($config->talk_in_frequency)->not->toBeEmpty();
    expect($config->public_description)->toContain('Get On The Air');
    expect($config->public_directions)->not->toBeEmpty();
    expect($config->public_visiting_hours)->not->toBeEmpty();
    expect($config->public_contact_email)->not->toBeEmpty();
});

test('seeder fills the bulletin sked with upcoming W1AW and K6KPH transmissions', function () {
    $event = Event::where('is_active', true)->firstOrFail();
    $entries = BulletinScheduleEntry::forEvent($event->id)->orderBy('scheduled_at')->get();

    expect($entries->count())->toBeGreaterThanOrEqual(3);
    expect($entries->pluck('source')->unique()->sort()->values()->all())->toBe(['K6KPH', 'W1AW']);
    expect($entries->pluck('mode')->unique())->toContain('cw', 'digital', 'phone');
    $entries->each(function (BulletinScheduleEntry $entry) use ($event) {
        expect($entry->scheduled_at->isFuture())->toBeTrue();
        expect($entry->scheduled_at->lt($event->end_time))->toBeTrue();
        expect($entry->created_at->lt($event->start_time))->toBeTrue();
    });

    expect(BulletinScheduleEntry::inReminderWindow()->exists())->toBeTrue();
});
