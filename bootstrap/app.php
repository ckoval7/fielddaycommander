<?php

use App\Http\Middleware\AuditLogger;
use App\Http\Middleware\CheckSystemSetupComplete;
use App\Http\Middleware\DevRoleOverride;
use App\Http\Middleware\EnforceRegistrationMode;
use App\Http\Middleware\Ensure2FAEnabled;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [
            DevRoleOverride::class,
            CheckSystemSetupComplete::class,
            EnforceRegistrationMode::class,
            EnsurePasswordChanged::class,
            Ensure2FAEnabled::class,
            AuditLogger::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session expired. Please sign in again.',
                    'redirect' => '/?session_expired=1',
                ], 419);
            }

            return redirect('/?session_expired=1');
        });
    })->create();
