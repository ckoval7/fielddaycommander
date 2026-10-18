<?php

use App\Support\TrustedHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/__trusted-network-probe', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'link' => url('/reset-password/token'),
    ]));
});

afterEach(function () {
    Request::setTrustedHosts([]);
});

test('forwarded headers from an untrusted public address are ignored', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.1'])
        ->getJson('/__trusted-network-probe')
        ->assertJson(['ip' => '203.0.113.9']);
});

test('forwarded headers from a LAN client are ignored by default', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.1'])
        ->getJson('/__trusted-network-probe')
        ->assertJson(['ip' => '192.168.1.50']);
});

test('forwarded headers from loopback are honored by default', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.1'])
        ->getJson('/__trusted-network-probe')
        ->assertJson(['ip' => '198.51.100.1']);
});

test('a configured proxy range is honored', function () {
    config(['trustedproxy.proxies' => '127.0.0.1,::1,172.16.0.0/12']);

    $this->withServerVariables(['REMOTE_ADDR' => '172.22.0.1'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.1'])
        ->getJson('/__trusted-network-probe')
        ->assertJson(['ip' => '198.51.100.1']);
});

test('trusted proxies can be narrowed through configuration', function () {
    config(['trustedproxy.proxies' => '10.0.0.5']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.42.0.7'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.1'])
        ->getJson('/__trusted-network-probe')
        ->assertJson(['ip' => '10.42.0.7']);
});

test('trusted host patterns cover the app url, its subdomains, loopback and extra hosts', function () {
    config(['app.url' => 'https://fd.example.org', 'trustedproxy.hosts' => 'FD.local']);

    $patterns = TrustedHosts::patterns();
    $matches = fn (string $host): bool => collect($patterns)->contains(fn (string $pattern) => preg_match('{'.$pattern.'}i', $host) === 1);

    expect($matches('fd.example.org'))->toBeTrue()
        ->and($matches('www.fd.example.org'))->toBeTrue()
        ->and($matches('localhost'))->toBeTrue()
        ->and($matches('127.0.0.1'))->toBeTrue()
        ->and($matches('[::1]'))->toBeTrue()
        ->and($matches('192.168.1.10'))->toBeTrue()
        ->and($matches('10.42.0.7'))->toBeTrue()
        ->and($matches('172.20.0.3'))->toBeTrue()
        ->and($matches('fd.local'))->toBeTrue()
        ->and($matches('fdcommander.local'))->toBeTrue()
        ->and($matches('100.64.0.1'))->toBeTrue()
        ->and($matches('100.127.255.254'))->toBeTrue()
        ->and($matches('[fd7a:115c:a1e0::1]'))->toBeTrue()
        ->and($matches('[fc00::1]'))->toBeTrue()
        ->and($matches('[fe80::1]'))->toBeTrue()
        ->and($matches('100.63.0.1'))->toBeFalse()
        ->and($matches('100.128.0.1'))->toBeFalse()
        ->and($matches('[2001:db8::1]'))->toBeFalse()
        ->and($matches('[::1].evil.example'))->toBeFalse()
        ->and($matches('evil.local.example'))->toBeFalse()
        ->and($matches('192.168.1.10.evil.example'))->toBeFalse()
        ->and($matches('203.0.113.5'))->toBeFalse()
        ->and($matches('172.32.0.1'))->toBeFalse()
        ->and($matches('evil.example'))->toBeFalse()
        ->and($matches('fd.example.org.evil.example'))->toBeFalse();
});

test('any host is accepted with a wildcard or while app url is localhost', function () {
    config(['app.url' => 'https://fd.example.org', 'trustedproxy.hosts' => '*']);
    expect(TrustedHosts::patterns())->toBe([]);

    config(['app.url' => 'http://localhost', 'trustedproxy.hosts' => null]);
    expect(TrustedHosts::patterns())->toBe([]);
});

test('links cannot be built from a spoofed host outside tests', function () {
    config(['app.url' => 'https://fd.example.org']);
    $this->app['env'] = 'production';

    $this->getJson('http://evil.example/__trusted-network-probe')
        ->assertBadRequest();
});

test('an untrusted host gets a page explaining how to trust it', function () {
    config(['app.url' => 'https://fd.example.org']);
    $this->app['env'] = 'production';

    $this->get('http://evil.example/__trusted-network-probe')
        ->assertBadRequest()
        ->assertSee("This address isn't trusted", false)
        ->assertSee('evil.example')
        ->assertSee('TRUSTED_HOSTS')
        ->assertSee('https://fd.example.org');
});

test('an untrusted host gets a json explanation for json requests', function () {
    config(['app.url' => 'https://fd.example.org']);
    $this->app['env'] = 'production';

    $this->getJson('http://evil.example/__trusted-network-probe')
        ->assertBadRequest()
        ->assertJson(['message' => "This address isn't in the server's trusted hosts. Ask your administrator to add it to TRUSTED_HOSTS."]);
});

test('lan, tailscale and mdns hosts are served outside tests', function (string $host) {
    config(['app.url' => 'https://fd.example.org']);
    $this->app['env'] = 'production';

    $this->getJson("http://{$host}/__trusted-network-probe")
        ->assertOk()
        ->assertJson(['link' => "http://{$host}/reset-password/token"]);
})->with(['192.168.1.50', '100.100.1.2', 'fdcommander.local']);

test('requests for the app url host are served outside tests', function () {
    config(['app.url' => 'https://fd.example.org']);
    $this->app['env'] = 'production';

    $this->getJson('https://fd.example.org/__trusted-network-probe')
        ->assertOk()
        ->assertJson(['link' => 'https://fd.example.org/reset-password/token']);
});

test('the health check answers on loopback outside tests', function () {
    config(['app.url' => 'https://fd.example.org']);
    $this->app['env'] = 'production';

    $this->get('http://127.0.0.1:8000/up')->assertOk();
});

test('the health check answers on a pod ip outside tests', function () {
    config(['app.url' => 'https://fd.example.org']);
    $this->app['env'] = 'production';

    $this->get('http://10.42.0.7:8000/up')->assertOk();
});
