<?php

use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mark system as set up
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create active event for tests
    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    // Create a user for authentication tests
    $this->user = User::factory()->create();

    // Create reference data
    $this->band = Band::first() ?? Band::create([
        'name' => '20m',
        'meters' => 20,
        'frequency_mhz' => 14.175,
    ]);

    $this->mode = Mode::first() ?? Mode::create([
        'name' => 'SSB',
        'category' => 'Phone',
        'points_fd' => 1,
        'points_wfd' => 1,
    ]);
});

describe('index route', function () {
    test('allows unauthenticated access to public logbook', function () {
        $response = $this->get(route('logbook.index'));

        $response->assertOk();
    });

    // Note: Full view rendering tests skipped due to MaryUI component resolution in test environment
    // The controller logic is tested via export tests and Livewire component tests handle UI
});

describe('export route', function () {
    test('returns CSV file with correct headers for authenticated user', function () {
        $this->actingAs($this->user);

        Contact::factory(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $response = $this->get(route('logbook.export'));

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition');

        // Check for CSV header row
        $content = $response->streamedContent();
        expect($content)->toContain('QSO Time')
            ->and($content)->toContain('Callsign')
            ->and($content)->toContain('Band')
            ->and($content)->toContain('Mode');
    });

    test('allows unauthenticated access to public export', function () {
        $response = $this->get(route('logbook.export'));

        $response->assertOk();
    });

    test('returns 404 when no active event exists', function () {
        $this->actingAs($this->user);

        // Delete event entirely so no context event is found
        $this->event->forceDelete();

        $response = $this->get(route('logbook.export'));

        $response->assertStatus(404);
    });

    test('export filename includes current date', function () {
        $this->actingAs($this->user);

        $response = $this->get(route('logbook.export'));

        $response->assertStatus(200);

        $disposition = $response->headers->get('Content-Disposition');
        $expectedDate = now()->format('Y-m-d');

        expect($disposition)->toContain($expectedDate)
            ->and($disposition)->toContain('logbook')
            ->and($disposition)->toContain('.csv');
    });

    test('exports only contacts from active event', function () {
        $this->actingAs($this->user);

        // Create contacts for active event
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'W1AW',
        ]);

        // Create contacts for different event
        $otherEvent = Event::factory()->create([
            'start_time' => now()->subDays(60),
            'end_time' => now()->subDays(59),
        ]);
        $otherEventConfig = EventConfiguration::factory()->create([
            'event_id' => $otherEvent->id,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $otherEventConfig->id,
            'callsign' => 'K1ZZ',
        ]);

        $response = $this->get(route('logbook.export'));

        $content = $response->streamedContent();
        expect($content)->toContain('W1AW')
            ->and($content)->not->toContain('K1ZZ');
    });

    test('applies band filter from query string', function () {
        $this->actingAs($this->user);

        $otherBand = Band::where('name', '!=', $this->band->name)->first() ?? Band::create([
            'name' => '40m',
            'meters' => 40,
            'frequency_mhz' => 7.0,
        ]);

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'band_id' => $this->band->id,
            'callsign' => 'W1AW',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'band_id' => $otherBand->id,
            'callsign' => 'K1ZZ',
        ]);

        $response = $this->get(route('logbook.export', ['band_id' => $this->band->id]));

        $content = $response->streamedContent();
        expect($content)->toContain('W1AW')
            ->and($content)->not->toContain('K1ZZ');
    });

    test('applies mode filter from query string', function () {
        $this->actingAs($this->user);

        $otherMode = Mode::where('name', '!=', $this->mode->name)->first() ?? Mode::create([
            'name' => 'CW',
            'category' => 'CW',
            'points_fd' => 2,
            'points_wfd' => 2,
        ]);

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'mode_id' => $otherMode->id,
            'callsign' => 'K1ZZ',
        ]);

        $response = $this->get(route('logbook.export', ['mode_id' => $this->mode->id]));

        $content = $response->streamedContent();
        expect($content)->toContain('W1AW')
            ->and($content)->not->toContain('K1ZZ');
    });

    test('applies callsign filter from query string', function () {
        $this->actingAs($this->user);

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'W1AW',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'K1ZZ',
        ]);

        $response = $this->get(route('logbook.export', ['callsign' => 'W1']));

        $content = $response->streamedContent();
        expect($content)->toContain('W1AW')
            ->and($content)->not->toContain('K1ZZ');
    });

    test('applies duplicate filter from query string', function () {
        $this->actingAs($this->user);

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'W1AW',
            'is_duplicate' => false,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'K1ZZ',
            'is_duplicate' => true,
        ]);

        $response = $this->get(route('logbook.export', ['duplicate_filter' => 'exclude']));

        $content = $response->streamedContent();
        expect($content)->toContain('W1AW')
            ->and($content)->not->toContain('K1ZZ');
    });

    test('exports all contacts when no filters provided', function () {
        $this->actingAs($this->user);

        Contact::factory(5)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $response = $this->get(route('logbook.export'));

        $content = $response->streamedContent();

        // Count lines (header + 5 data rows)
        $lines = array_filter(explode("\n", $content), fn ($line) => trim($line) !== '');
        expect($lines)->toHaveCount(6); // 1 header + 5 contacts
    });
});

describe('route registration', function () {
    test('logbook index route has correct name', function () {
        expect(route('logbook.index'))->toEndWith('/logbook');
    });

    test('logbook export route has correct name', function () {
        expect(route('logbook.export'))->toEndWith('/logbook/export');
    });

    test('logbook export route is accessible', function () {
        $this->actingAs($this->user);

        $response = $this->get(route('logbook.export'));

        $response->assertSuccessful();
    });
});
