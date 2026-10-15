<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Scoring\Bonuses\WinterFieldDay2026\SixContinuousHoursStrategy as WinterFieldDay2026SixContinuousHoursStrategy;

/**
 * WFD 2027 objective "six_continuous_hours". OM x2.
 *
 * Unchanged from 2026: time spent monitoring counts, so a gap in the log does
 * not disprove it. Claimed by hand.
 */
class SixContinuousHoursStrategy extends WinterFieldDay2026SixContinuousHoursStrategy {}
