<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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

test('resolves every WSJT mode to Digital', function (string $mode) {
    expect($this->resolver->resolve($mode))->toBe($this->modeDigital->id);
})->with(['FT2', 'FST4', 'FT4', 'FT8', 'JT4', 'JT9', 'JT65', 'Q65', 'MSK144', 'WSPR', 'FST4W', 'ECHO']);

test('resolves JTTY, which WFD 2027 permits, to Digital', function () {
    expect($this->resolver->resolve('JTTY'))->toBe($this->modeDigital->id);
});

test('resolves an unknown mode to Digital', function () {
    expect($this->resolver->resolve('UNKNOWN_MODE'))->toBe($this->modeDigital->id);
});

test('returns null for null input', function () {
    expect($this->resolver->resolve(null))->toBeNull();
});

test('returns null for a blank mode', function (string $mode) {
    expect($this->resolver->resolve($mode))->toBeNull();
})->with(['', '   ']);

test('resolves fldigi sub-modes to Digital', function (string $mode) {
    expect($this->resolver->resolve($mode))->toBe($this->modeDigital->id);
})->with(['PSK125', 'MFSK16', 'MFSK32', 'OLIVIA 8/250', 'THOR', 'MT63', 'CONTESTI', 'DOMINO']);

test('resolves analog phone modes to Phone', function (string $mode) {
    expect($this->resolver->resolve($mode))->toBe($this->modePhone->id);
})->with(['SSB', 'USB', 'LSB', 'FM', 'AM', 'PM']);

test('resolves digital voice to Phone', function (string $mode) {
    expect($this->resolver->resolve($mode))->toBe($this->modePhone->id);
})->with(['FREEDV', 'DSTAR', 'DMR', 'C4FM', 'M17', 'DIGITALVOICE']);

test('resolves CW variants a rig reports to CW', function (string $mode) {
    expect($this->resolver->resolve($mode))->toBe($this->modeCw->id);
})->with(['CW-R', 'CWR', 'CW-U', 'CW-L', 'cw-r']);

test('resolves rig data modes to Digital', function (string $mode) {
    expect($this->resolver->resolve($mode))->toBe($this->modeDigital->id);
})->with(['PKTUSB', 'USB-D', 'DATA-U']);

test('falls back to the ADIF parent mode for an unknown voice sub-mode', function () {
    expect($this->resolver->resolve('NEWVOICE', 'DIGITALVOICE'))->toBe($this->modePhone->id);
});

test('a known sub-mode wins over its parent mode', function () {
    expect($this->resolver->resolve('MFSK16', 'MFSK'))->toBe($this->modeDigital->id)
        ->and($this->resolver->resolve('USB', 'SSB'))->toBe($this->modePhone->id);
});

test('suggests digital voice sub-modes under Phone, not Digital', function () {
    expect(ModeResolverService::submodeSuggestions('Phone'))->toContain('FREEDV', 'DSTAR', 'DMR', 'C4FM')
        ->and(ModeResolverService::submodeSuggestions('Digital'))->not->toContain('FREEDV');
});
