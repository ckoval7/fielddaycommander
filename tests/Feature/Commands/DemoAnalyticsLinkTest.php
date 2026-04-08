<?php

use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('demo.enabled', true);
});

it('generates a signed dashboard URL', function () {
    $this->artisan('demo:analytics-link')
        ->assertSuccessful()
        ->expectsOutputToContain('/demo/analytics?');
});

it('generates a signed API URL with --api flag', function () {
    $this->artisan('demo:analytics-link', ['--api' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('/demo/analytics/api?');
});

it('includes the range parameter in the URL', function () {
    $this->artisan('demo:analytics-link', ['--range' => '30d'])
        ->assertSuccessful()
        ->expectsOutputToContain('range=30d');
});

it('defaults to 7d range', function () {
    $this->artisan('demo:analytics-link')
        ->assertSuccessful()
        ->expectsOutputToContain('range=7d');
});

it('includes the expiry duration in the output', function () {
    $this->artisan('demo:analytics-link', ['--hours' => 48])
        ->assertSuccessful()
        ->expectsOutputToContain('48 hours');
});

it('exits early when demo mode is disabled', function () {
    Config::set('demo.enabled', false);

    $this->artisan('demo:analytics-link')
        ->expectsOutputToContain('Demo mode is disabled')
        ->assertSuccessful();
});

it('rejects invalid range values', function () {
    $this->artisan('demo:analytics-link', ['--range' => 'bogus'])
        ->assertFailed();
});
