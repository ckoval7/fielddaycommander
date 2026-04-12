<?php

namespace App\Enums;

enum AdifImportStatus: string
{
    case PendingMapping = 'pending_mapping';
    case PendingReview = 'pending_review';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';
}
