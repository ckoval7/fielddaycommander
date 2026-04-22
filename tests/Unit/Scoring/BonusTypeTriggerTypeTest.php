<?php

use App\Models\BonusType;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);
});

it('derived codes are classified correctly', function (string $code) {
    $bt = BonusType::where('code', $code)->first();
    expect($bt)->not->toBeNull()->and($bt->trigger_type)->toBe('derived');
})->with([
    ['sm_sec_message'],
    ['nts_message'],
    ['w1aw_bulletin'],
    ['elected_official_visit'],
    ['agency_visit'],
    ['media_publicity'],
]);

it('youth_participation is hybrid', function () {
    expect(BonusType::where('code', 'youth_participation')->first()?->trigger_type)->toBe('hybrid');
});

it('social_media is manual', function () {
    expect(BonusType::where('code', 'social_media')->first()?->trigger_type)->toBe('manual');
});
