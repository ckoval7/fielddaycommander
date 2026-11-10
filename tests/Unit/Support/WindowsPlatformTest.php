<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Support\WindowsCertificateStore;
use App\Support\WindowsSafeFilesystem;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;

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

describe('safe filesystem', function () {
    beforeEach(function () {
        $this->dir = storage_path('framework/testing/safe-fs-'.Str::uuid());
        File::ensureDirectoryExists($this->dir);
    });

    afterEach(function () {
        File::deleteDirectory($this->dir);
    });

    test('writes the file normally', function () {
        (new WindowsSafeFilesystem)->replace("{$this->dir}/view.php", 'compiled');

        expect(File::get("{$this->dir}/view.php"))->toBe('compiled')
            ->and(File::files($this->dir))->toHaveCount(1);
    });

    test('when another request already put the view in place, the duplicate is dropped', function () {
        File::put("{$this->dir}/view.php", 'compiled by the other request');
        $files = new class extends WindowsSafeFilesystem
        {
            protected function moveIntoPlace(string $tempPath, string $path): bool
            {
                return false; // What Windows does while the other request has it open
            }
        };

        $files->replace("{$this->dir}/view.php", 'compiled');

        expect(File::get("{$this->dir}/view.php"))->toBe('compiled by the other request')
            ->and(File::files($this->dir))->toHaveCount(1);
    });

    test('the Blade compiler can be given the safe filesystem after it was built', function () {
        $compiler = new BladeCompiler(new Filesystem, $this->dir);
        $files = new WindowsSafeFilesystem;

        WindowsSafeFilesystem::useIn($compiler, $files);

        expect((fn () => $this->files)->call($compiler))->toBe($files);
    });

    test('a write that fails with nothing in place is still an error', function () {
        $files = new class extends WindowsSafeFilesystem
        {
            protected function moveIntoPlace(string $tempPath, string $path): bool
            {
                return false;
            }
        };

        expect(fn () => $files->replace("{$this->dir}/view.php", 'compiled'))->toThrow(RuntimeException::class);
    });
});
