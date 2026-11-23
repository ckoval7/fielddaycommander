<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Providers;

use App\Models\DemoRelayRoute;
use App\Models\Relay;
use App\Support\DemoDatabase;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class DemoServiceProvider extends ServiceProvider
{
    /**
     * Queue payload key that records which visitor database queued a job.
     */
    public const QUEUE_PAYLOAD_KEY = 'demoDatabase';

    /**
     * Bootstrap services.
     *
     * Queued jobs (including queued broadcasts) must run against the visitor
     * database they were dispatched from, or SerializesModels restores their
     * models — and builds their channel names — from whichever database the
     * worker last pointed at.
     */
    public function boot(): void
    {
        // Does nothing outside demo mode, since DemoDatabase::current() is then null.
        Relay::created(self::rememberEnrollmentCode(...));

        self::configureRateLimiters();

        if (! config('demo.enabled')) {
            return;
        }

        Queue::createPayloadUsing(function (): array {
            $dbName = DemoDatabase::current();

            return $dbName ? [self::QUEUE_PAYLOAD_KEY => $dbName] : [];
        });

        Queue::before(function (JobProcessing $event): void {
            $dbName = $event->job->payload()[self::QUEUE_PAYLOAD_KEY] ?? null;

            if (DemoDatabase::isVisitorDatabase($dbName)) {
                DemoDatabase::activate($dbName);
            }
        });
    }

    /**
     * Throttles for the demo's own routes, keyed on the visitor's address.
     *
     * An unnamed throttle keys on the signed-in user when one resolves, and
     * these routes run before the visitor's sandbox is selected, so the user
     * would come from whichever sandbox the worker served last. Every sandbox
     * numbers its personas alike, which would share one counter across
     * unrelated visitors.
     */
    private static function configureRateLimiters(): void
    {
        RateLimiter::for('demo-provision', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('demo-reset', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
        // Generous: a club sharing one address can have many tabs open.
        RateLimiter::for('demo-heartbeat', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip()));
    }

    /**
     * Relay API requests don't carry the demo_session cookie, so record which
     * sandbox a new enrollment code belongs to (see DemoRelayMiddleware).
     */
    public static function rememberEnrollmentCode(Relay $relay): void
    {
        $dbName = DemoDatabase::current();

        if ($dbName !== null && $relay->enrollment_code_hash !== null) {
            DemoRelayRoute::remember($relay->enrollment_code_hash, $dbName);
        }
    }
}
