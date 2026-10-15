<?php

namespace App\Scoring\Bonuses\WinterFieldDay2027;

use App\Scoring\Bonuses\WinterFieldDay2026\SpecialBulletinStrategy;

/**
 * WFD 2027 objective "bulletin". OM x1.
 *
 * The 2026 special bulletin renamed; still earned by saving the copy.
 */
class BulletinStrategy extends SpecialBulletinStrategy
{
    public function code(): string
    {
        return 'bulletin';
    }
}
