<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services\N3fjp;

/**
 * The kinds of message an N3FJP network client sends its server.
 */
enum N3fjpMessageKind
{
    /** A request for the full log (`<NTWK><OPEN>`). */
    case Open;

    /** A request for the server's record count and last record number (`<NTWK><CHECK>`). */
    case Check;

    /** A logged, edited or deleted contact (`<NTWK>` with a TRANSACTION). */
    case Transaction;

    /** The station's current band and mode (`<BAMS>`). */
    case BandAndMode;

    /** A chat message (`<MESG>`). */
    case Chat;

    /** A request to set every client's clock (`<SCLK>`). */
    case ClockSync;

    /** A DX spot (`<DXSP>`). */
    case DxSpot;

    /** A request for every station's band and mode (`<WHO>`). */
    case Who;

    case Unknown;
}
