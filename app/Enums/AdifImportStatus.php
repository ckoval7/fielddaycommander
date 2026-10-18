<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Enums;

enum AdifImportStatus: string
{
    case PendingMapping = 'pending_mapping';
    case Validating = 'validating';
    case PendingReview = 'pending_review';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';
}
