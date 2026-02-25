<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Permission::firstOrCreate(['name' => 'view-reports']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('view-reports');

    $section = Section::factory()->create(['code' => 'CT']);
    $eventType = EventType::factory()->create(['code' => 'FD', 'name' => 'Field Day', 'is_active' => true]);
    $opClass = OperatingClass::create([
        'code' => '2A',
        'event_type_id' => $eventType->id,
        'name' => 'Class 2A',
        'description' => 'Two transmitters',
        'allows_gota' => false,
        'allows_free_vhf' => false,
        'requires_emergency_power' => false,
    ]);

    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'callsign' => 'W1AW',
        'club_name' => 'Anytown ARC',
        'section_id' => $section->id,
        'operating_class_id' => $opClass->id,
        'max_power_watts' => 100,
    ]);
});

describe('cabrillo endpoint', function () {
    test('redirects unauthenticated user to login', function () {
        $this->get(route('reports.cabrillo'))
            ->assertRedirect(route('login'));
    });

    test('returns 200 with text/plain content type for authorised user', function () {
        $this->actingAs($this->user)
            ->get(route('reports.cabrillo'))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    });

    test('response body contains cabrillo start and end markers', function () {
        $content = $this->actingAs($this->user)
            ->get(route('reports.cabrillo'))
            ->streamedContent();

        expect($content)
            ->toContain('START-OF-LOG: 3.0')
            ->toContain('END-OF-LOG:');
    });

    test('filename header contains callsign and year', function () {
        $year = now()->subHours(12)->year;

        $disposition = $this->actingAs($this->user)
            ->get(route('reports.cabrillo'))
            ->headers->get('Content-Disposition');

        expect($disposition)
            ->toContain('w1aw')
            ->toContain((string) $year);
    });

    test('returns 404 when no active event exists', function () {
        $this->event->update([
            'start_time' => now()->addDays(10),
            'end_time' => now()->addDays(11),
        ]);

        $this->actingAs($this->user)
            ->get(route('reports.cabrillo'))
            ->assertNotFound();
    });
});

describe('club-summary endpoint', function () {
    test('redirects unauthenticated user to login', function () {
        $this->get(route('reports.club-summary'))
            ->assertRedirect(route('login'));
    });

    test('returns 200 with application/pdf content type for authorised user', function () {
        $this->actingAs($this->user)
            ->get(route('reports.club-summary'))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'application/pdf');
    });

    test('returns 404 when no active event exists', function () {
        $this->event->update([
            'start_time' => now()->addDays(10),
            'end_time' => now()->addDays(11),
        ]);

        $this->actingAs($this->user)
            ->get(route('reports.club-summary'))
            ->assertNotFound();
    });
});
