<?php

use App\Models\Mode;
use App\Services\ModeResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->modeCw = Mode::create(['name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
    $this->modePhone = Mode::create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->modeDigital = Mode::create(['name' => 'Digital', 'category' => 'Digital', 'points_fd' => 2, 'points_wfd' => 2]);

    $this->resolver = new ModeResolverService;
});

test('resolves CW mode', function () {
    expect($this->resolver->resolve('CW'))->toBe($this->modeCw->id);
});

test('resolves CW case-insensitively', function () {
    expect($this->resolver->resolve('cw'))->toBe($this->modeCw->id);
});

test('resolves SSB to Phone', function () {
    expect($this->resolver->resolve('SSB'))->toBe($this->modePhone->id);
});

test('resolves USB to Phone', function () {
    expect($this->resolver->resolve('USB'))->toBe($this->modePhone->id);
});

test('resolves LSB to Phone', function () {
    expect($this->resolver->resolve('LSB'))->toBe($this->modePhone->id);
});

test('resolves FM to Phone', function () {
    expect($this->resolver->resolve('FM'))->toBe($this->modePhone->id);
});

test('resolves RTTY to Digital', function () {
    expect($this->resolver->resolve('RTTY'))->toBe($this->modeDigital->id);
});

test('resolves FT8 to Digital', function () {
    expect($this->resolver->resolve('FT8'))->toBe($this->modeDigital->id);
});

test('resolves PSK31 to Digital', function () {
    expect($this->resolver->resolve('PSK31'))->toBe($this->modeDigital->id);
});

test('returns null for unknown mode', function () {
    expect($this->resolver->resolve('UNKNOWN_MODE'))->toBeNull();
});

test('returns null for null input', function () {
    expect($this->resolver->resolve(null))->toBeNull();
});
