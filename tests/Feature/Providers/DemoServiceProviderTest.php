<?php

use App\Providers\DemoServiceProvider;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

const QUEUED_VISITOR = 'demo_aaaaaaaa_aaaa_4aaa_8aaa_aaaaaaaaaaaa';

beforeEach(function () {
    Config::set('demo.enabled', true);
    (new DemoServiceProvider(app()))->boot();
});

/**
 * Fire the queue's JobProcessing event for a job with the given payload.
 *
 * @param  array<string, mixed>  $payload
 */
function processJobWithPayload(array $payload): void
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn($payload);

    event(new JobProcessing('redis', $job));
}

test('records the active visitor database on queued job payloads', function () {
    Config::set('database.connections.demo.database', QUEUED_VISITOR);

    Queue::connection('database')->push('App\\Jobs\\Example');

    $payload = json_decode(DB::table('jobs')->value('payload'), true);

    expect($payload[DemoServiceProvider::QUEUE_PAYLOAD_KEY])->toBe(QUEUED_VISITOR);
});

test('leaves job payloads untouched outside a visitor database', function () {
    Config::set('database.connections.demo.database', 'demo_base');

    Queue::connection('database')->push('App\\Jobs\\Example');

    $payload = json_decode(DB::table('jobs')->value('payload'), true);

    expect($payload)->not->toHaveKey(DemoServiceProvider::QUEUE_PAYLOAD_KEY);
});

test('switches to the queued visitor database before a job runs', function () {
    Config::set('database.connections.demo.database', 'demo_base');

    processJobWithPayload([DemoServiceProvider::QUEUE_PAYLOAD_KEY => QUEUED_VISITOR]);

    expect(config('database.connections.demo.database'))->toBe(QUEUED_VISITOR);
});

test('ignores payloads without a valid visitor database', function (array $payload) {
    Config::set('database.connections.demo.database', 'demo_base');

    processJobWithPayload($payload);

    expect(config('database.connections.demo.database'))->toBe('demo_base');
})->with([
    'missing' => [[]],
    'not a visitor database' => [[DemoServiceProvider::QUEUE_PAYLOAD_KEY => 'mysql']],
]);
