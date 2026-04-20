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
