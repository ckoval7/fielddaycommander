<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

enum AdifRecordStatus: string
{
    case Pending = 'pending';
    case Mapped = 'mapped';
    case DuplicateMatch = 'duplicate_match';
    case Inconsistency = 'inconsistency';
    case Ready = 'ready';
    case Imported = 'imported';
    case Skipped = 'skipped';
    case Merged = 'merged';
    case Invalid = 'invalid';
}
