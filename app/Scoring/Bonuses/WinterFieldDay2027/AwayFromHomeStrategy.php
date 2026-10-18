<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Scoring\Bonuses\WinterFieldDay2026\AwayFromHomeStrategy as WinterFieldDay2026AwayFromHomeStrategy;

/**
 * WFD 2027 objective "away_from_home". OM x3.
 *
 * Unchanged from 2026 apart from wording: the site must be more than half a
 * mile from home, which is not derivable from logged data, so it is claimed.
 */
class AwayFromHomeStrategy extends WinterFieldDay2026AwayFromHomeStrategy {}
