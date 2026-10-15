<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Scoring\Bonuses\WinterFieldDay2026\QrpStrategy as WinterFieldDay2026QrpStrategy;

/**
 * WFD 2027 objective "qrp". OM x4.
 *
 * Unchanged from 2026: 10 W or less on Phone, 5 W or less on CW or Digital,
 * each contact judged against its own mode's ceiling.
 */
class QrpStrategy extends WinterFieldDay2026QrpStrategy {}
