<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Support\WindowsCertificateStore;
use App\Support\WindowsViewRace;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

describe('certificate store', function () {
    test('on Windows, HTTPS requests trust the Windows certificate store', function () {
        $http = new Factory;

        WindowsCertificateStore::apply($http, 'Windows');

        expect($http->createPendingRequest()->getOptions()['curl'] ?? [])
            ->toBe([CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA]);
    });

    test('elsewhere, requests keep the system bundle', function () {
        $http = new Factory;

        WindowsCertificateStore::apply($http, 'Linux');

        expect($http->createPendingRequest()->getOptions())->not->toHaveKey('curl');
    });
});

describe('view race', function () {
    beforeEach(function () {
        $this->dir = storage_path('framework/testing/view-race-'.Str::uuid());
        File::ensureDirectoryExists($this->dir);
        $this->temporary = "{$this->dir}\\3a46FA1.tmp";
        $this->target = "{$this->dir}/3a4c2289d9537b3eb737ee24335703d1.blade.php";
        File::put($this->temporary, 'compiled');
    });

    afterEach(function () {
        File::deleteDirectory($this->dir);
    });

    function renameWarning(string $from, string $to): string
    {
        return "rename({$from},{$to}): Access is denied (code: 5)";
    }

    test('a rename that lost to another request writing the same view is ignored and its temporary file removed', function () {
        File::put($this->target, 'compiled by the other request');
        $temporary = str_replace('\\', '/', $this->temporary);

        expect(WindowsViewRace::isLostRace(E_WARNING, renameWarning($temporary, $this->target), $this->dir))->toBeTrue()
            ->and(File::exists($temporary))->toBeFalse()
            ->and(File::get($this->target))->toBe('compiled by the other request');
    });

    test('a rename with nothing in place still fails', function () {
        expect(WindowsViewRace::isLostRace(E_WARNING, renameWarning($this->temporary, $this->target), $this->dir))->toBeFalse();
    });

    test('renames outside the compiled views folder are left alone', function () {
        $elsewhere = storage_path('app/private/photo.jpg');

        expect(WindowsViewRace::isLostRace(E_WARNING, renameWarning($this->temporary, $elsewhere), $this->dir))->toBeFalse();
    });

    test("the compiler's touch of an unchanged view is ignored, other touches aren't", function () {
        $compiler = base_path('vendor/laravel/framework/src/Illuminate/View/Compilers/BladeCompiler.php');

        expect(WindowsViewRace::isLostRace(E_WARNING, 'touch(): Utime failed: Permission denied', $this->dir, $compiler))->toBeTrue()
            ->and(WindowsViewRace::isLostRace(E_WARNING, 'touch(): Utime failed: Permission denied', $this->dir, app_path('Services/Other.php')))->toBeFalse();
    });

    test('other warnings pass through', function () {
        expect(WindowsViewRace::isLostRace(E_WARNING, 'Undefined variable $x', $this->dir))->toBeFalse()
            ->and(WindowsViewRace::isLostRace(E_NOTICE, renameWarning($this->temporary, $this->target), $this->dir))->toBeFalse();
    });
});
