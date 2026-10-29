<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Middleware;

use App\Support\HtmlMinifier;
use App\Support\IconSprite;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trims full HTML pages: icons outside Livewire components share one sprite
 * before `</body>`, and template whitespace and comments are stripped.
 *
 * Livewire update requests are skipped; LeanComponentHtml trims those
 * per component.
 */
class LeanHtmlResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolved per request: IconSprite is scoped, and Octane reuses middleware.
        $sprite = app(IconSprite::class);
        $pageRequest = $request->isMethod('GET') && ! $request->hasHeader('X-Livewire');

        if ($pageRequest) {
            $sprite->begin();
        }

        $response = $next($request);

        if (! $pageRequest) {
            return $response;
        }

        $symbols = $sprite->endAll();

        if (! $response instanceof IlluminateResponse || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $content = $response->getContent();

        if (is_string($content) && $content !== '') {
            // setContent() replaces the original view, which tests and listeners inspect.
            $original = $response->original;
            $response->setContent(HtmlMinifier::minify(IconSprite::appendToBody($content, $symbols)));
            $response->original = $original;
        }

        return $response;
    }
}
