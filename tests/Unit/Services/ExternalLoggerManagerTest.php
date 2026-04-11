<?php

use App\Models\EventConfiguration;
use App\Models\ExternalLoggerSetting;
use App\Services\ExternalLoggerManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->config = EventConfiguration::factory()->create();
    $this->manager = new ExternalLoggerManager;
});

test('creates setting when enabling for first time', function () {
    $this->manager->enable($this->config->id, 'n1mm', 12060);

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'n1mm')
        ->first();

    expect($setting)->not->toBeNull()
        ->and($setting->is_enabled)->toBeTrue()
        ->and($setting->port)->toBe(12060);
});

test('updates existing setting when enabling', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => false,
        'port' => 12060,
    ]);

    $this->manager->enable($this->config->id, 'n1mm', 12070);

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'n1mm')
        ->first();

    expect($setting->is_enabled)->toBeTrue()
        ->and($setting->port)->toBe(12070);
});

test('disables listener', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
    ]);

    $this->manager->disable($this->config->id, 'n1mm');

    $setting = ExternalLoggerSetting::where('event_configuration_id', $this->config->id)
        ->where('listener_type', 'n1mm')
        ->first();

    expect($setting->is_enabled)->toBeFalse();
});

test('checks if listener is enabled', function () {
    expect($this->manager->isEnabled($this->config->id, 'n1mm'))->toBeFalse();

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
    ]);

    expect($this->manager->isEnabled($this->config->id, 'n1mm'))->toBeTrue();
});

test('gets setting for listener', function () {
    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12080,
    ]);

    $setting = $this->manager->getSetting($this->config->id, 'n1mm');

    expect($setting)->not->toBeNull()
        ->and($setting->port)->toBe(12080);
});

test('returns null setting when none exists', function () {
    expect($this->manager->getSetting($this->config->id, 'n1mm'))->toBeNull();
});

test('gets all enabled settings across events', function () {
    $config2 = EventConfiguration::factory()->create();

    ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
    ]);
    ExternalLoggerSetting::create([
        'event_configuration_id' => $config2->id,
        'listener_type' => 'n1mm',
        'is_enabled' => false,
        'port' => 12060,
    ]);

    $enabled = $this->manager->getEnabledSettings('n1mm');

    expect($enabled)->toHaveCount(1)
        ->and($enabled->first()->event_configuration_id)->toBe($this->config->id);
});
