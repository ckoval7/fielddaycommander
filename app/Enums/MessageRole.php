<?php

namespace App\Enums;

enum MessageRole: string
{
    case Originated = 'originated';
    case Relayed = 'relayed';
    case ReceivedDelivered = 'received_delivered';

    public function label(): string
    {
        return match ($this) {
            self::Originated => 'Originated',
            self::Relayed => 'Relayed',
            self::ReceivedDelivered => 'Received & Delivered',
        };
    }
}
