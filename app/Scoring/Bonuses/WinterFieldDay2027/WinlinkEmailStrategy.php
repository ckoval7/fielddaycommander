<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Scoring\Bonuses\WinterFieldDay2026\WinlinkEmailStrategy as WinterFieldDay2026WinlinkEmailStrategy;

/**
 * WFD 2027 objective "winlink_email". OM x1.
 *
 * Unchanged in substance from 2026. Winlink traffic is not logged as a
 * contact, so the objective is claimed by hand with the message as proof.
 */
class WinlinkEmailStrategy extends WinterFieldDay2026WinlinkEmailStrategy {}
