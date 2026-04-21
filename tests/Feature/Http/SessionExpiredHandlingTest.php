<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

test('web token mismatch redirects to session_expired landing', function () {
    $handler = app(ExceptionHandler::class);
    $request = Request::create('/some-path', 'POST');

    $response = $handler->render($request, new TokenMismatchException('csrf'));

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toBe(url('/?session_expired=1'));
});

test('json token mismatch returns 419 with redirect payload', function () {
    $handler = app(ExceptionHandler::class);
    $request = Request::create('/some-path', 'POST', server: [
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $response = $handler->render($request, new TokenMismatchException('csrf'));

    expect($response->getStatusCode())->toBe(419);
    expect($response->headers->get('Content-Type'))->toContain('application/json');

    $payload = json_decode($response->getContent(), true);
    expect($payload['redirect'])->toBe('/?session_expired=1');
    expect($payload['message'])->toBe('Your session expired. Please sign in again.');
});

test('livewire token mismatch returns 419 json even without accept header', function () {
    // Livewire 4's /livewire/update sends Content-Type: application/json and
    // X-Livewire: 1 but no Accept header, so expectsJson() is false. Without
    // this path the handler would 302, fetch would follow, and Livewire would
    // blow up trying to JSON.parse the redirected HTML.
    $handler = app(ExceptionHandler::class);
    $request = Request::create('/livewire/update', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_LIVEWIRE' => '1',
    ]);

    $response = $handler->render($request, new TokenMismatchException('csrf'));

    expect($response->getStatusCode())->toBe(419);
    expect($response->headers->get('Content-Type'))->toContain('application/json');

    $payload = json_decode($response->getContent(), true);
    expect($payload['redirect'])->toBe('/?session_expired=1');
});

test('json content-type without accept header returns 419 json', function () {
    $handler = app(ExceptionHandler::class);
    $request = Request::create('/some-path', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
    ]);

    $response = $handler->render($request, new TokenMismatchException('csrf'));

    expect($response->getStatusCode())->toBe(419);
    expect($response->headers->get('Content-Type'))->toContain('application/json');
});
