<?php

use App\Models\EventConfiguration;
use App\Models\Station;
use App\Services\StationResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->config = EventConfiguration::factory()->create();
    $this->resolver = new StationResolverService;
});

test('matches station by exact name (case-insensitive)', function () {
    $station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'CW-80m',
    ]);

    $result = $this->resolver->resolve('cw-80m', $this->config->id);

    expect($result->id)->toBe($station->id);
});

test('matches station by hostname when name does not match', function () {
    $station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => '80m CW Station',
        'hostname' => 'CONTEST-PC',
    ]);

    $result = $this->resolver->resolve('CONTEST-PC', $this->config->id);

    expect($result->id)->toBe($station->id);
});

test('hostname match is case-insensitive', function () {
    $station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => '80m CW Station',
        'hostname' => 'CONTEST-PC',
    ]);

    $result = $this->resolver->resolve('contest-pc', $this->config->id);

    expect($result->id)->toBe($station->id);
});

test('name match takes priority over hostname match', function () {
    $stationByName = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'CONTEST-PC',
        'hostname' => null,
    ]);
    Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'Other Station',
        'hostname' => 'CONTEST-PC',
    ]);

    $result = $this->resolver->resolve('CONTEST-PC', $this->config->id);

    expect($result->id)->toBe($stationByName->id);
});

test('auto-creates station when no match found', function () {
    $result = $this->resolver->resolve('NEW-STATION', $this->config->id);

    expect($result->name)->toBe('NEW-STATION')
        ->and($result->hostname)->toBe('NEW-STATION')
        ->and($result->event_configuration_id)->toBe($this->config->id)
        ->and($result->wasRecentlyCreated)->toBeTrue();
});

test('does not match stations from other events', function () {
    $otherConfig = EventConfiguration::factory()->create();
    Station::factory()->create([
        'event_configuration_id' => $otherConfig->id,
        'name' => 'CW-80m',
    ]);

    $result = $this->resolver->resolve('CW-80m', $this->config->id);

    expect($result->event_configuration_id)->toBe($this->config->id)
        ->and($result->wasRecentlyCreated)->toBeTrue();
});

test('returns whether station was auto-created', function () {
    Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'EXISTING',
    ]);

    $existing = $this->resolver->resolve('EXISTING', $this->config->id);
    $created = $this->resolver->resolve('BRAND-NEW', $this->config->id);

    expect($existing->wasRecentlyCreated)->toBeFalse()
        ->and($created->wasRecentlyCreated)->toBeTrue();
});
