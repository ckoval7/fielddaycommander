<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Scoring\Bonuses\WinterFieldDay2026\MultipleAntennasStrategy as WinterFieldDay2026MultipleAntennasStrategy;

/**
 * WFD 2027 objective "multiple_antennas". OM x1.
 *
 * Unchanged from 2026. Antennas are not modelled per contact, so which ones
 * were newly deployed cannot be derived from the log.
 */
class MultipleAntennasStrategy extends WinterFieldDay2026MultipleAntennasStrategy {}
