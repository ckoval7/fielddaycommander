<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Middleware;

use App\Models\DemoRelayRoute;
use App\Models\Relay;
use App\Support\DemoDatabase;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Points relay API requests at the sandbox the relay was enrolled in.
 *
 * Relays send a bearer token (or, when enrolling, the code) and never the
 * demo_session cookie, so DemoMiddleware can't place them. The secret's hash
 * is looked up in demo_relay_routes instead. A request whose sandbox can't be
 * found is refused here, so it never runs against whichever database the
 * Octane worker last served.
 */
class DemoRelayMiddleware
{
    public const ENROLL_PATH = 'api/relay/v1/enroll';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.enabled') || ! $request->is('api/relay/*')) {
            return $next($request);
        }

        $enrolling = $request->is(self::ENROLL_PATH);
        $secretHash = $this->secretHash($request, $enrolling);
        $database = $secretHash === null ? null : DemoRelayRoute::databaseFor($secretHash);

        if ($database === null || ! DemoDatabase::isVisitorDatabase($database) || ! $this->databaseExists($database)) {
            return $enrolling ? $this->invalidCode() : $this->unauthenticated();
        }

        DemoDatabase::activate($database);

        $response = $next($request);

        if ($enrolling && $response->getStatusCode() === Response::HTTP_CREATED) {
            $this->rememberToken($response, $database);
        }

        return $response;
    }

    /**
     * The hash of the secret the request carries, matching Relay::hashSecret.
     */
    private function secretHash(Request $request, bool $enrolling): ?string
    {
        if (! $enrolling) {
            $token = $request->bearerToken();

            return $token === null ? null : Relay::hashSecret($token);
        }

        $code = $request->input('code');

        if (! is_string($code)) {
            return null;
        }

        // Same normalization RelayEnrollmentService applies before hashing a code.
        return Relay::hashSecret(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }

    /**
     * A successful enrollment hands out a token, which later requests route by.
     */
    private function rememberToken(Response $response, string $database): void
    {
        if (! $response instanceof JsonResponse) {
            return;
        }

        $token = $response->getData(true)['token'] ?? null;

        if (is_string($token)) {
            DemoRelayRoute::remember(Relay::hashSecret($token), $database);
        }
    }

    /**
     * Sandboxes expire, so a known secret can outlive its database.
     */
    protected function databaseExists(string $database): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::select(
            'SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = ?',
            [$database]
        ) !== [];
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json(['code' => 'unauthenticated', 'message' => 'The relay token is missing or invalid.'], 401);
    }

    private function invalidCode(): JsonResponse
    {
        return response()->json([
            'code' => 'invalid_code',
            'message' => 'This enrollment code is invalid, expired, used, or revoked.',
        ], 422);
    }
}
