<?php

namespace App\Observers;

use App\Models\W1awBulletin;
use App\Services\MessageBonusSyncService;

class W1awBulletinObserver
{
    public function __construct(protected MessageBonusSyncService $syncService) {}

    public function created(W1awBulletin $bulletin): void
    {
        $this->syncService->sync($bulletin->eventConfiguration);
    }

    public function updated(W1awBulletin $bulletin): void
    {
        $this->syncService->sync($bulletin->eventConfiguration);
    }

    public function deleted(W1awBulletin $bulletin): void
    {
        $this->syncService->sync($bulletin->eventConfiguration);
    }

    public function restored(W1awBulletin $bulletin): void
    {
        $this->syncService->sync($bulletin->eventConfiguration);
    }
}
