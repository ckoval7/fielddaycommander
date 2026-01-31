<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditLogger
{
    protected array $auditableRoutes = [
        'POST /login' => 'user.login.attempt',
        'POST /logout' => 'user.logout',
        'POST /two-factor-challenge' => 'user.2fa.challenge',
        'POST /register' => 'user.register',
        'POST /password/reset' => 'user.password.reset',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->logIfAuditable($request, $response);

        return $response;
    }

    protected function logIfAuditable(Request $request, Response $response): void
    {
        $method = $request->method();
        $path = $request->path();
        $routeKey = "{$method} /{$path}";

        if (isset($this->auditableRoutes[$routeKey])) {
            $action = $this->auditableRoutes[$routeKey];

            AuditLog::log(
                action: $action,
                userId: auth()->id(),
                isCritical: str_contains($action, 'login') || str_contains($action, '2fa')
            );
        }
    }
}
