<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Providers;

use App\Models\DemoRelayRoute;
use App\Models\Relay;
use App\Support\DemoDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
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
