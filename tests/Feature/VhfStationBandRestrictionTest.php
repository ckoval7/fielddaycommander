<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\DTOs\ExternalContactDto;
use App\Exceptions\InvalidExternalContactException;
use App\Livewire\Logging\LoggingInterface;
use App\Livewire\Logging\StationSelect;
use App\Livewire\Logging\TranscribeInterface;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\ExternalContactHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    $this->travelTo(now()->setTime(20, 0));

    $this->twenty = Band::updateOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0, 'allowed_fd' => true, 'sort_order' => 6]);
    $this->six = Band::updateOrCreate(['name' => '6m'], ['meters' => 6, 'frequency_mhz' => 50.0, 'is_vhf_uhf' => true, 'allowed_fd' => true, 'sort_order' => 9]);
    $this->two = Band::updateOrCreate(['name' => '2m'], ['meters' => 2, 'frequency_mhz' => 144.0, 'is_vhf_uhf' => true, 'allowed_fd' => true, 'sort_order' => 10]);
    $this->satellite = Band::updateOrCreate(['name' => 'Satellite'], ['meters' => null, 'frequency_mhz' => null, 'is_satellite' => true, 'allowed_fd' => true, 'sort_order' => 25]);

    $this->phone = Mode::firstOrCreate(['name' => 'Phone'], ['category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->section = Section::firstOrCreate(
        ['code' => 'CT'],
        ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true],
    );

    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->config = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    $this->vhfStation = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'VHF',
        'is_gota' => false,
        'is_vhf_only' => true,
    ]);

    $this->user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');
    $this->user->assignRole($role);

    $this->vhfMessage = 'VHF is marked VHF/UHF Only, so it can\'t log on 20m. Choose 6m or a higher band.';
});

function vhfStationDto(string $bandName, ?string $externalId = null): ExternalContactDto
{
    return new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: now(),
        source: 'n1mm',
        bandName: $bandName,
        modeName: 'Phone',
        stationIdentifier: 'VHF',
        sectionCode: 'CT',
        externalId: $externalId,
    );
}

test('bands at or above 50 MHz and satellite are allowed at the free VHF station, HF is not', function () {
    $hfStation = Station::factory()->create(['event_configuration_id' => $this->config->id, 'is_vhf_only' => false]);

    expect($this->twenty->isAllowedAtStation($this->vhfStation))->toBeFalse()
        ->and($this->six->isAllowedAtStation($this->vhfStation))->toBeTrue()
        ->and($this->satellite->isAllowedAtStation($this->vhfStation))->toBeTrue()
        ->and($this->twenty->isAllowedAtStation($hfStation))->toBeTrue()
        ->and(Band::query()->allowedAtStation($this->vhfStation)->pluck('name')->all())
        ->not->toContain('20m')
        ->toContain('6m', '2m', 'Satellite');
});

describe('station select', function () {
    test('refuses to start a session below 50 MHz at the free VHF station', function () {
        $this->actingAs($this->user);

        Livewire::test(StationSelect::class)
            ->set('selectedStationId', $this->vhfStation->id)
            ->set('selectedBandId', $this->twenty->id)
            ->set('selectedModeId', $this->phone->id)
            ->set('powerWatts', 10)
            ->call('startSession')
            ->assertHasErrors(['selectedBandId'])
            ->assertSee($this->vhfMessage)
            ->assertNoRedirect();

        expect(OperatingSession::count())->toBe(0);
    });

    test('starts a 6m session at the free VHF station', function () {
        $this->actingAs($this->user);

        Livewire::test(StationSelect::class)
            ->set('selectedStationId', $this->vhfStation->id)
            ->set('selectedBandId', $this->six->id)
            ->set('selectedModeId', $this->phone->id)
            ->set('powerWatts', 10)
            ->call('startSession')
            ->assertHasNoErrors()
            ->assertRedirect();

        expect(OperatingSession::first()->band_id)->toBe($this->six->id);
    });

    test('lists only 6m and up for the free VHF station', function () {
        $this->actingAs($this->user);

        $bandNames = Livewire::test(StationSelect::class)
            ->call('selectStation', $this->vhfStation->id)
            ->instance()
            ->bands
            ->pluck('name')
            ->all();

        expect($bandNames)->not->toContain('20m')->toContain('6m');
    });
});

describe('band switch', function () {
    beforeEach(function () {
        $this->session = OperatingSession::factory()->active()->create([
            'station_id' => $this->vhfStation->id,
            'operator_user_id' => $this->user->id,
            'band_id' => $this->six->id,
            'mode_id' => $this->phone->id,
            'power_watts' => 10,
        ]);
    });

    test('refuses to switch the free VHF station to an HF band', function () {
        $this->actingAs($this->user);

        $result = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->call('switchBandMode', $this->twenty->id, $this->phone->id)
            ->effects['returns'][0];

        expect($result)->toBe(['ok' => false, 'message' => $this->vhfMessage])
            ->and($this->session->fresh()->end_time)->toBeNull()
            ->and(OperatingSession::count())->toBe(1);
    });

    test('offers only 6m and up in the switch pickers', function () {
        $this->actingAs($this->user);

        $bandNames = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->instance()
            ->switchBands
            ->pluck('name')
            ->all();

        expect($bandNames)->not->toContain('20m')->toContain('6m', '2m');
    });

    test('gives the browser the VHF/UHF Only reason for each band the station cannot switch to', function () {
        $this->actingAs($this->user);

        $component = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session]);

        $restricted = collect($component->instance()->restrictedSwitchBands);

        expect($restricted->firstWhere('name', '20m'))->toBe(['name' => '20m', 'message' => $this->vhfMessage])
            ->and($restricted->pluck('name')->all())->not->toContain('6m', '2m', 'Satellite');

        $component->assertSeeHtml('restricted_bands:');
    });

    test('shows a band the station can use in the band switch hint', function () {
        Band::updateOrCreate(['name' => '40m'], ['meters' => 40, 'frequency_mhz' => 7.0, 'allowed_fd' => true, 'sort_order' => 5]);
        $this->actingAs($this->user);

        Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->assertSeeHtml('<span class="font-mono font-semibold text-base-content">6M</span>')
            ->assertDontSeeHtml('<span class="font-mono font-semibold text-base-content">40M</span>');
    });

    test('switches the free VHF station to another VHF band', function () {
        $this->actingAs($this->user);

        $result = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
            ->call('switchBandMode', $this->two->id, $this->phone->id)
            ->effects['returns'][0];

        expect($result['ok'])->toBeTrue();
    });
});

describe('transcribe paper log', function () {
    test('refuses an HF contact at the free VHF station', function () {
        $this->actingAs($this->user);

        Livewire::test(TranscribeInterface::class, ['station' => $this->vhfStation])
            ->set('selectedBandId', $this->twenty->id)
            ->set('selectedModeId', $this->phone->id)
            ->set('exchangeInput', 'W5XYZ 1B CT')
            ->call('logContact')
            ->assertSet('parseError', $this->vhfMessage);

        expect(Contact::count())->toBe(0);
    });

    test('logs a 6m contact at the free VHF station', function () {
        $this->actingAs($this->user);

        Livewire::test(TranscribeInterface::class, ['station' => $this->vhfStation])
            ->set('selectedBandId', $this->six->id)
            ->set('selectedModeId', $this->phone->id)
            ->set('exchangeInput', 'W5XYZ 1B CT')
            ->call('logContact')
            ->assertSet('parseError', '');

        expect(Contact::count())->toBe(1);
    });

    test('lists only 6m and up for the free VHF station', function () {
        $this->actingAs($this->user);

        $bandNames = Livewire::test(TranscribeInterface::class, ['station' => $this->vhfStation])
            ->instance()
            ->bands
            ->pluck('name')
            ->all();

        expect($bandNames)->not->toContain('20m')->toContain('6m');
    });
});

describe('contact sync', function () {
    test('rejects a contact on an HF session at the free VHF station', function () {
        // A session left on HF from before the station was flagged VHF-only.
        $session = OperatingSession::factory()->active()->create([
            'station_id' => $this->vhfStation->id,
            'operator_user_id' => $this->user->id,
            'band_id' => $this->twenty->id,
            'mode_id' => $this->phone->id,
            'power_watts' => 10,
        ]);

        $this->actingAs($this->user)
            ->postJson('/logging/contacts', [
                'uuid' => fake()->uuid(),
                'operating_session_id' => $session->id,
                'band_id' => $this->twenty->id,
                'mode_id' => $this->phone->id,
                'callsign' => 'W1AW',
                'section_id' => $this->section->id,
                'exchange_class' => '3A',
                'power_watts' => 10,
                'qso_time' => now()->toISOString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['band_id' => $this->vhfMessage]);

        expect(Contact::count())->toBe(0);
    });
});

describe('external loggers', function () {
    test('drops an HF contact at the free VHF station with a log line', function () {
        Log::spy();

        expect(fn () => app(ExternalContactHandler::class)->handleContact(vhfStationDto('20m'), $this->config))
            ->toThrow(function (InvalidExternalContactException $exception): void {
                expect($exception->rejectionReason)->toBe('below 50 MHz at VHF station');
            });

        expect(Contact::count())->toBe(0)
            ->and(OperatingSession::count())->toBe(0);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Dropped external contact below 50 MHz at the free VHF station'
                && $context['band'] === '20m' && $context['station'] === 'VHF')
            ->once();
    });

    test('accepts a 6m contact at the free VHF station', function () {
        $contact = app(ExternalContactHandler::class)->handleContact(vhfStationDto('6m'), $this->config);

        expect($contact->band_id)->toBe($this->six->id);
    });

    test('ignores a replace that moves a VHF station contact to HF', function () {
        $contact = app(ExternalContactHandler::class)->handleContact(vhfStationDto('6m', 'qso-1'), $this->config);

        expect(fn () => app(ExternalContactHandler::class)->handleReplace(vhfStationDto('20m', 'qso-1'), $this->config))
            ->toThrow(InvalidExternalContactException::class);

        expect($contact->fresh()->band_id)->toBe($this->six->id);
    });
});
