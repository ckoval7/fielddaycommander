<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        $preAuthUserId = auth()->id();

        $response = $next($request);

        $this->logIfAuditable($request, $preAuthUserId);

        return $response;
    }

    protected function logIfAuditable(Request $request, ?int $preAuthUserId): void
    {
        $method = $request->method();
        $path = $request->path();
        $routeKey = "{$method} /{$path}";

        match ($routeKey) {
            'POST /login' => $this->logLogin(),
            'POST /logout' => $this->logAction('user.logout', $preAuthUserId),
            'POST /two-factor-challenge' => $this->logTwoFactorChallenge(),
            'POST /register' => $this->logRegister(),
            'POST /password/reset' => $this->logAction('user.password.reset', auth()->id()),
            default => null,
        };
    }

    protected function logLogin(): void
    {
        if (auth()->check()) {
            AuditLog::log(action: 'user.login.success');
        } else {
            AuditLog::log(
                action: 'user.login.failed',
                newValues: ['email' => request()->input('email')],
                isCritical: true
            );
        }
    }

    protected function logTwoFactorChallenge(): void
    {
        if (auth()->check()) {
            AuditLog::log(action: 'user.login.success');
        } else {
            AuditLog::log(action: 'user.login.2fa_failed', isCritical: true);
        }
    }

    protected function logRegister(): void
    {
        if (auth()->check()) {
            AuditLog::log(action: 'user.register', auditable: auth()->user());
        }
    }

    protected function logAction(string $action, ?int $userId, bool $isCritical = false): void
    {
        AuditLog::log(action: $action, userId: $userId, isCritical: $isCritical);
    }
}
