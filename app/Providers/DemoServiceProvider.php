<?php

namespace App\Providers;

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
}
