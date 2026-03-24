<?php

namespace App\Observers;

use App\Models\Message;
use App\Services\MessageBonusSyncService;

class MessageObserver
{
    public function __construct(protected MessageBonusSyncService $syncService) {}

    public function created(Message $message): void
    {
        $this->syncService->sync($message->eventConfiguration);
    }

    public function updated(Message $message): void
    {
        $this->syncService->sync($message->eventConfiguration);
    }

    public function deleted(Message $message): void
    {
        $this->syncService->sync($message->eventConfiguration);
    }

    public function restored(Message $message): void
    {
        $this->syncService->sync($message->eventConfiguration);
    }
}
