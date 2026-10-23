<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Logbook\LogbookBrowser;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

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

    describe('filters from the logbook browser', function () {
        beforeEach(function () {
            $this->otherBand = Band::where('id', '!=', $this->band->id)->first()
                ?? Band::create(['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.0]);
            $this->otherMode = Mode::where('id', '!=', $this->mode->id)->first()
                ?? Mode::create(['name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
            $this->section = Section::first() ?? Section::create(['name' => 'Connecticut', 'code' => 'CT']);
            $this->otherSection = Section::where('id', '!=', $this->section->id)->first()
                ?? Section::create(['name' => 'Maine', 'code' => 'ME']);
            $this->station = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
            $this->otherStation = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
            $this->logger = User::factory()->create();
            $this->actingAs($this->user);

            $this->contactFor = fn (string $callsign, array $attributes = []) => Contact::factory()->create(array_merge([
                'event_configuration_id' => $this->eventConfig->id,
                'callsign' => $callsign,
                'band_id' => $this->band->id,
                'mode_id' => $this->mode->id,
                'section_id' => $this->section->id,
                'qso_time' => now()->subHours(2),
                'is_duplicate' => false,
                'is_transcribed' => false,
                'is_gota_contact' => false,
            ], $attributes));
        });

        test('narrows the export to the contacts the filter keeps', function (array $case) {
            [$parameters, $kept, $dropped] = $case;

            $exported = exportedCallsigns($this->get(route('logbook.export', $parameters)));

            expect($exported)->toContain($kept)
                ->and($exported)->not->toContain($dropped);
        })->with([
            'bandIds' => function () {
                ($this->contactFor)('W1KEEP');
                ($this->contactFor)('K1DROP', ['band_id' => $this->otherBand->id]);

                return [['bandIds' => [$this->band->id]], 'W1KEEP', 'K1DROP'];
            },
            'modeIds' => function () {
                ($this->contactFor)('W1KEEP');
                ($this->contactFor)('K1DROP', ['mode_id' => $this->otherMode->id]);

                return [['modeIds' => [$this->mode->id]], 'W1KEEP', 'K1DROP'];
            },
            'stationIds' => function () {
                ($this->contactFor)('W1KEEP', [
                    'operating_session_id' => OperatingSession::factory()->create(['station_id' => $this->station->id])->id,
                ]);
                ($this->contactFor)('K1DROP', [
                    'operating_session_id' => OperatingSession::factory()->create(['station_id' => $this->otherStation->id])->id,
                ]);

                return [['stationIds' => [$this->station->id]], 'W1KEEP', 'K1DROP'];
            },
            'operatorIds' => function () {
                ($this->contactFor)('W1KEEP', ['logger_user_id' => $this->logger->id]);
                ($this->contactFor)('K1DROP', ['logger_user_id' => User::factory()->create()->id]);

                return [['operatorIds' => [$this->logger->id]], 'W1KEEP', 'K1DROP'];
            },
            'timeFrom' => function () {
                ($this->contactFor)('K1DROP', ['qso_time' => '2025-02-07 09:00:00']);
                ($this->contactFor)('W1KEEP', ['qso_time' => '2025-02-07 11:00:00']);

                return [['timeFrom' => '2025-02-07 10:00:00'], 'W1KEEP', 'K1DROP'];
            },
            'timeTo' => function () {
                ($this->contactFor)('W1KEEP', ['qso_time' => '2025-02-07 19:00:00']);
                ($this->contactFor)('K1DROP', ['qso_time' => '2025-02-07 21:00:00']);

                return [['timeTo' => '2025-02-07 20:00:00'], 'W1KEEP', 'K1DROP'];
            },
            'callsignSearch' => function () {
                ($this->contactFor)('W1KEEP');
                ($this->contactFor)('K1DROP');

                return [['callsignSearch' => 'w1k'], 'W1KEEP', 'K1DROP'];
            },
            'sectionIds' => function () {
                ($this->contactFor)('W1KEEP');
                ($this->contactFor)('K1DROP', ['section_id' => $this->otherSection->id]);

                return [['sectionIds' => [$this->section->id]], 'W1KEEP', 'K1DROP'];
            },
            'showDuplicates only' => function () {
                ($this->contactFor)('W1KEEP', ['is_duplicate' => true, 'points' => 0]);
                ($this->contactFor)('K1DROP');

                return [['showDuplicates' => 'only'], 'W1KEEP', 'K1DROP'];
            },
            'showDuplicates exclude' => function () {
                ($this->contactFor)('W1KEEP');
                ($this->contactFor)('K1DROP', ['is_duplicate' => true, 'points' => 0]);

                return [['showDuplicates' => 'exclude'], 'W1KEEP', 'K1DROP'];
            },
            'showTranscribed' => function () {
                ($this->contactFor)('W1KEEP', ['is_transcribed' => true]);
                ($this->contactFor)('K1DROP');

                return [['showTranscribed' => 'only'], 'W1KEEP', 'K1DROP'];
            },
            'showGota only' => function () {
                ($this->contactFor)('W1KEEP', ['is_gota_contact' => true]);
                ($this->contactFor)('K1DROP');

                return [['showGota' => 'only'], 'W1KEEP', 'K1DROP'];
            },
            'showGota exclude' => function () {
                ($this->contactFor)('W1KEEP');
                ($this->contactFor)('K1DROP', ['is_gota_contact' => true]);

                return [['showGota' => 'exclude'], 'W1KEEP', 'K1DROP'];
            },
            'needsAttention' => function () {
                ($this->contactFor)('W1KEEP', ['band_id' => null]);
                ($this->contactFor)('K1DROP');

                return [['needsAttention' => 'only'], 'W1KEEP', 'K1DROP'];
            },
        ]);

        test('combines filters the same way the browser does', function () {
            ($this->contactFor)('W1KEEP', ['logger_user_id' => $this->logger->id]);
            ($this->contactFor)('W1DROP', ['logger_user_id' => $this->logger->id, 'band_id' => $this->otherBand->id]);
            ($this->contactFor)('K1DROP', ['band_id' => $this->otherBand->id]);

            $state = [
                'bandIds' => [$this->band->id],
                'operatorIds' => [$this->logger->id],
                'callsignSearch' => 'W1',
            ];

            $component = Livewire::test(LogbookBrowser::class);
            foreach ($state as $property => $value) {
                $component->set($property, $value);
            }
            $listed = collect($component->instance()->contacts->items())->pluck('callsign')->sort()->values()->all();

            $exported = exportedCallsigns($this->get($component->instance()->exportUrl));

            expect($listed)->toBe(['W1KEEP'])
                ->and($exported)->toBe($listed);
        });

        test('exports deleted contacts only for users who may edit contacts', function () {
            Permission::findOrCreate('edit-contacts', 'web');
            ($this->contactFor)('W1LIVE');
            ($this->contactFor)('K1GONE')->delete();

            $parameters = ['showDeleted' => 'only'];

            expect(exportedCallsigns($this->get(route('logbook.export', $parameters))))
                ->toBe(['W1LIVE']);

            $this->user->givePermissionTo('edit-contacts');

            expect(exportedCallsigns($this->get(route('logbook.export', $parameters))))
                ->toBe(['K1GONE']);
        });

        test('ignores malformed filter values', function () {
            ($this->contactFor)('W1AW');

            $response = $this->get(route('logbook.export', [
                'bandIds' => 'not-an-array',
                'modeIds' => ['abc'],
                'callsignSearch' => ['nested'],
            ]));

            $response->assertOk();
            expect(exportedCallsigns($response))->toBe(['W1AW']);
        });
    });

    describe('selected event', function () {
        beforeEach(function () {
            $this->pastEvent = Event::factory()->create([
                'start_time' => now()->subDays(60),
                'end_time' => now()->subDays(59),
            ]);
            $this->pastConfig = EventConfiguration::factory()->create(['event_id' => $this->pastEvent->id]);

            Contact::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'callsign' => 'W1NOW']);
            Contact::factory()->create(['event_configuration_id' => $this->pastConfig->id, 'callsign' => 'K1PAST']);
        });

        test('exports the event named in the link instead of the current one', function () {
            $exported = exportedCallsigns($this->get(route('logbook.export', ['event' => $this->pastEvent->id])));

            expect($exported)->toBe(['K1PAST']);
        });

        test('falls back to the current event when the named event does not exist', function () {
            $exported = exportedCallsigns($this->get(route('logbook.export', ['event' => 999999])));

            expect($exported)->toBe(['W1NOW']);
        });

        test('does not export a deleted event', function () {
            $this->pastEvent->delete();

            $exported = exportedCallsigns($this->get(route('logbook.export', ['event' => $this->pastEvent->id])));

            expect($exported)->toBe(['W1NOW']);
        });

        test('browser export link names the event on screen', function () {
            $component = Livewire::withQueryParams(['event' => $this->pastEvent->id])->test(LogbookBrowser::class);

            expect(exportedCallsigns($this->get($component->instance()->exportUrl)))->toBe(['K1PAST']);
        });
    });

    test('browser export link follows filter changes made after the page loads', function () {
        Contact::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'callsign' => 'W1AW']);
        Contact::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'callsign' => 'K1ZZ']);

        Livewire::test(LogbookBrowser::class)
            ->set('callsignSearch', 'K1')
            ->assertSee(e(route('logbook.export', ['event' => $this->event->id, 'callsignSearch' => 'K1'])), false);
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

/**
 * Callsigns in an exported CSV, sorted.
 *
 * @return list<string>
 */
function exportedCallsigns(TestResponse $response): array
{
    $response->assertOk();

    $rows = array_map(str_getcsv(...), array_filter(explode("\n", $response->streamedContent()), fn (string $line): bool => trim($line) !== ''));
    array_shift($rows);

    $callsigns = array_map(fn (array $row): string => $row[1], $rows);
    sort($callsigns);

    return $callsigns;
}

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
