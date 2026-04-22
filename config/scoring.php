<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Use BonusStrategy classes instead of sync services
    |--------------------------------------------------------------------------
    |
    | Phase 2 guard: when true, MessageBonusSyncService and
    | GuestbookBonusSyncService skip their writes, letting the versioned
    | strategy classes produce the authoritative EventBonus rows.
    |
    | Flipped to true in Phase 3 at which point the sync services are
    | deleted outright.
    */
    'use_bonus_strategies' => env('SCORING_USE_BONUS_STRATEGIES', false),
];
