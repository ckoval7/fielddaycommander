<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Middleware;

use App\Services\EventContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEventIsScored
{
    public function __construct(protected EventContextService $eventContextService) {}

    /**
     * Hide contest-only pages (scoring, section map, contest reports) when
     * the event in context is not scored. Pages with no event in context
     * pass through and show their own empty state.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $event = $this->eventContextService->getContextEvent();

        if ($event !== null && ! $event->isScored()) {
            abort(404);
        }

        return $next($request);
    }
}
