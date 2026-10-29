<?php

// SPDX-FileCopyrightText: Taylor Otwell
// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later AND MIT

use App\Http\Middleware\AuditLogger;
use App\Http\Middleware\CheckSystemSetupComplete;
use App\Http\Middleware\DevRoleOverride;
use App\Http\Middleware\EnforceRegistrationMode;
use App\Http\Middleware\EnforceStationMode;
use App\Http\Middleware\Ensure2FAEnabled;
use App\Http\Middleware\EnsureAccountNotLocked;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\LeanHtmlResponse;
use App\Http\Middleware\SecurityHeaders;
use App\Support\LocalNetworkPusherServer;
use App\Support\TrustedHosts;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Reverb\Protocols\Pusher\Server as PusherServer;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
        ]);
        // Trusted proxies come from config('trustedproxy.proxies'), which TrustProxies reads per request.
        $middleware->trustHosts(at: fn (): array => TrustedHosts::patterns(), subdomains: false);
        $middleware->append(SecurityHeaders::class);
        $middleware->append(LeanHtmlResponse::class);
        $middleware->web(append: [
            EnsureAccountNotLocked::class,
            DevRoleOverride::class,
            CheckSystemSetupComplete::class,
            EnforceRegistrationMode::class,
            EnforceStationMode::class,
            EnsurePasswordChanged::class,
            Ensure2FAEnabled::class,
            AuditLogger::class,
        ]);
    })
    ->withBindings([
        // Accept WebSocket origins on the local network (LAN IPs, Tailscale, *.local).
        PusherServer::class => LocalNetworkPusherServer::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        // A Host outside TRUSTED_HOSTS surfaces as a bare 400. Explain how to fix it,
        // without url() helpers, which would read the rejected Host again.
        $exceptions->render(function (BadRequestHttpException $e, Request $request) {
            $previous = $e->getPrevious();

            if (! $previous instanceof SuspiciousOperationException || ! str_starts_with($previous->getMessage(), 'Untrusted Host')) {
                return null;
            }

            $message = "This address isn't in the server's trusted hosts. Ask your administrator to add it to TRUSTED_HOSTS.";

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 400);
            }

            return response()->view('errors.untrusted-host', [
                'host' => (string) $request->server('HTTP_HOST', ''),
                'appUrl' => (string) config('app.url', ''),
            ], 400);
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            // Livewire's /livewire/update request sends Content-Type:
            // application/json but no Accept header, so expectsJson() is false.
            // Detect JSON-style requests via Content-Type or the X-Livewire
            // header so we return a JSON redirect payload instead of a 302 —
            // fetch auto-follows 302s and Livewire then tries to JSON.parse
            // the redirected HTML.
            if ($request->expectsJson() || $request->isJson() || $request->hasHeader('X-Livewire')) {
                return response()->json([
                    'message' => 'Your session expired. Please sign in again.',
                    'redirect' => '/?session_expired=1',
                ], 419);
            }

            return redirect('/?session_expired=1');
        });
    })->create();
