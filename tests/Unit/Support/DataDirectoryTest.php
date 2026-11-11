<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Support\DataDirectory;

beforeEach(function () {
    $this->originalStorage = $this->app->storagePath();
    $this->originalEnvironment = $this->app->environmentPath();
});

afterEach(function () {
    $this->app->useStoragePath($this->originalStorage);
    $this->app->useEnvironmentPath($this->originalEnvironment);

    foreach (['APP_CONFIG_CACHE', 'APP_EVENTS_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'FDC_DATA_PATH'] as $key) {
        unset($_SERVER[$key]);
    }
});

test('a data folder moves .env, storage and the bootstrap caches out of the code folder', function () {
    DataDirectory::apply($this->app, '/data/');

    expect($this->app->environmentFilePath())->toBe('/data'.DIRECTORY_SEPARATOR.'.env')
        ->and($this->app->storagePath('logs'))->toBe('/data/storage'.DIRECTORY_SEPARATOR.'logs')
        ->and($this->app->getCachedConfigPath())->toBe('/data/cache/config.php')
        ->and($this->app->getCachedRoutesPath())->toBe('/data/cache/routes-v7.php')
        ->and($this->app->getCachedPackagesPath())->toBe('/data/cache/packages.php');
});

test('a Windows drive path is kept absolute for the bootstrap caches', function () {
    DataDirectory::apply($this->app, 'C:\\ProgramData\\Field Day Commander');

    expect($this->app->getCachedConfigPath())
        ->toBe('C:\\ProgramData\\Field Day Commander'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'config.php');
});

test('the data folder can come from FDC_DATA_PATH', function () {
    $_SERVER['FDC_DATA_PATH'] = '/data';

    DataDirectory::apply($this->app);

    expect($this->app->storagePath())->toBe('/data/storage');
});

test('a cache path set on its own wins over the data folder', function () {
    $_SERVER['APP_CONFIG_CACHE'] = '/elsewhere/config.php';

    DataDirectory::apply($this->app, '/data');

    expect($this->app->getCachedConfigPath())->toBe('/elsewhere/config.php');
});

test('without a data folder nothing moves', function () {
    DataDirectory::apply($this->app);

    expect($this->app->storagePath())->toBe($this->originalStorage)
        ->and($this->app->environmentPath())->toBe($this->originalEnvironment);
});
