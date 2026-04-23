<?php

namespace App\Observers;

use App\Models\Message;
use App\Scoring\DomainEvents\MessageChanged;

class MessageObserver
{
    public function saved(Message $message): void
    {
        event(new MessageChanged($message, $message->event_configuration_id));
    }

    public function deleted(Message $message): void
    {
        event(new MessageChanged($message, $message->event_configuration_id));
    }

    public function restored(Message $message): void
    {
        event(new MessageChanged($message, $message->event_configuration_id));
    }
}
