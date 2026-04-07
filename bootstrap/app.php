<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withEvents(discover: false)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/demo.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);
        $middleware->trustProxies(at: '*');
        $middleware->encryptCookies(except: ['demo_session']);
        $middleware->web(append: [
            \App\Http\Middleware\DemoMiddleware::class,
            \App\Http\Middleware\DemoAnalyticsMiddleware::class,
            \App\Http\Middleware\DevRoleOverride::class,
            \App\Http\Middleware\CheckSystemSetupComplete::class,
            \App\Http\Middleware\EnforceRegistrationMode::class,
            \App\Http\Middleware\EnsurePasswordChanged::class,
            \App\Http\Middleware\Ensure2FAEnabled::class,
            \App\Http\Middleware\AuditLogger::class,
        ]);

        // Ensure DemoMiddleware swaps the DB connection before Laravel's
        // auth middleware tries to resolve the user from the session.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\DemoMiddleware::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
