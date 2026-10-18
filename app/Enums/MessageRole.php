<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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
