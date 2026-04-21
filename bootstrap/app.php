<?php

use App\Http\Middleware\AuditLogger;
use App\Http\Middleware\CheckSystemSetupComplete;
use App\Http\Middleware\DemoAnalyticsMiddleware;
use App\Http\Middleware\DemoMiddleware;
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
            'verified' => EnsureEmailIsVerified::class,
        ]);
        $middleware->trustProxies(at: '*');
        $middleware->encryptCookies(except: ['demo_session']);
        $middleware->web(append: [
            DemoMiddleware::class,
            DemoAnalyticsMiddleware::class,
            DevRoleOverride::class,
            CheckSystemSetupComplete::class,
            EnforceRegistrationMode::class,
            EnsurePasswordChanged::class,
            Ensure2FAEnabled::class,
            AuditLogger::class,
        ]);

        // Ensure DemoMiddleware swaps the DB connection before Laravel's
        // auth middleware tries to resolve the user from the session.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: DemoMiddleware::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
