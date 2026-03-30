<?php

use App\Models\OperatingSession;
use App\Models\User;
use App\Services\NotificationService;

it('sends station occupied notification when a regular session is created', function () {
    $mock = Mockery::mock(NotificationService::class);
    $mock->shouldReceive('notifyAll')
        ->once()
        ->withArgs(fn ($category) => $category->value === 'station_status');
    $this->app->instance(NotificationService::class, $mock);

    User::factory()->create();

    OperatingSession::factory()->create();
});

it('does not send station occupied notification for transcription sessions', function () {
    $mock = Mockery::mock(NotificationService::class);
    $mock->shouldNotReceive('notifyAll');
    $this->app->instance(NotificationService::class, $mock);

    User::factory()->create();

    OperatingSession::factory()->create([
        'is_transcription' => true,
        'end_time' => now(),
    ]);
});

it('does not send station available notification when transcription session is updated', function () {
    // Create the transcription session without observer interference
    $mock = Mockery::mock(NotificationService::class);
    $mock->shouldNotReceive('notifyAll');
    $this->app->instance(NotificationService::class, $mock);

    $session = OperatingSession::factory()->create([
        'is_transcription' => true,
        'end_time' => now(),
    ]);

    // Update end_time — should still not notify
    $session->update(['end_time' => now()->addHour()]);
});
