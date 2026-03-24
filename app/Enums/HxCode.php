<?php

namespace App\Enums;

enum HxCode: string
{
    case Hxa = 'hxa';
    case Hxb = 'hxb';
    case Hxc = 'hxc';
    case Hxd = 'hxd';
    case Hxe = 'hxe';
    case Hxf = 'hxf';
    case Hxg = 'hxg';

    public function label(): string
    {
        return match ($this) {
            self::Hxa => 'HXA - Collect delivery authorized',
            self::Hxb => 'HXB - Cancel if not delivered in time',
            self::Hxc => 'HXC - Report delivery date/time',
            self::Hxd => 'HXD - Report origin station and delivery',
            self::Hxe => 'HXE - Get reply from addressee',
            self::Hxf => 'HXF - Hold delivery until date',
            self::Hxg => 'HXG - No toll delivery required',
        };
    }
}
