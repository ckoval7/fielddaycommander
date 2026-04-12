<?php

use App\Models\Band;
use App\Services\BandResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->band160m = Band::create(['name' => '160m', 'meters' => 160, 'frequency_mhz' => 1.8, 'sort_order' => 1]);
    $this->band80m = Band::create(['name' => '80m', 'meters' => 80, 'frequency_mhz' => 3.5, 'sort_order' => 2]);
    $this->band40m = Band::create(['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.0, 'sort_order' => 3]);
    $this->band20m = Band::create(['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    $this->band15m = Band::create(['name' => '15m', 'meters' => 15, 'frequency_mhz' => 21.0, 'sort_order' => 5]);
    $this->band10m = Band::create(['name' => '10m', 'meters' => 10, 'frequency_mhz' => 28.0, 'sort_order' => 6]);
    $this->band6m = Band::create(['name' => '6m', 'meters' => 6, 'frequency_mhz' => 50.0, 'sort_order' => 7]);
    $this->band2m = Band::create(['name' => '2m', 'meters' => 2, 'frequency_mhz' => 144.0, 'sort_order' => 8]);

    $this->resolver = new BandResolverService;
});

test('resolves band by name case-insensitively', function () {
    expect($this->resolver->resolveByName('20M'))->toBe($this->band20m->id);
});

test('resolves band by lowercase name', function () {
    expect($this->resolver->resolveByName('40m'))->toBe($this->band40m->id);
});

test('returns null for unknown band name', function () {
    expect($this->resolver->resolveByName('12m'))->toBeNull();
});

test('returns null for null band name', function () {
    expect($this->resolver->resolveByName(null))->toBeNull();
});

test('resolves 20m from frequency in Hz', function () {
    expect($this->resolver->resolveByFrequencyHz(14200000))->toBe($this->band20m->id);
});

test('resolves 40m from frequency in Hz', function () {
    expect($this->resolver->resolveByFrequencyHz(7125000))->toBe($this->band40m->id);
});

test('resolves 80m from frequency in Hz', function () {
    expect($this->resolver->resolveByFrequencyHz(3525000))->toBe($this->band80m->id);
});

test('resolves 160m from frequency in Hz', function () {
    expect($this->resolver->resolveByFrequencyHz(1812000))->toBe($this->band160m->id);
});

test('resolves 10m from frequency in Hz', function () {
    expect($this->resolver->resolveByFrequencyHz(28400000))->toBe($this->band10m->id);
});

test('resolves 15m from frequency in Hz', function () {
    expect($this->resolver->resolveByFrequencyHz(21200000))->toBe($this->band15m->id);
});

test('resolves 6m from frequency in Hz', function () {
    expect($this->resolver->resolveByFrequencyHz(50125000))->toBe($this->band6m->id);
});

test('resolves 2m from frequency in Hz', function () {
    expect($this->resolver->resolveByFrequencyHz(146520000))->toBe($this->band2m->id);
});

test('returns null for frequency outside any band', function () {
    expect($this->resolver->resolveByFrequencyHz(100000000))->toBeNull();
});

test('returns null for null frequency', function () {
    expect($this->resolver->resolveByFrequencyHz(null))->toBeNull();
});
