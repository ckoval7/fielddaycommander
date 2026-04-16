<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true'],
    );
});

/**
 * @return string First path-d segment of the given rendered SVG icon.
 */
function phosphorPathSignature(string $name): string
{
    $svg = (string) svg($name)->toHtml();
    preg_match('/<path[^>]*\sd="([^"]+)"/', $svg, $m);

    return $m[1] ?? '';
}

test('authenticated home page renders Phosphor nav icons and no stale Heroicon nav names', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->followingRedirects()->get('/');
    $response->assertOk();

    $html = $response->getContent();

    // Phosphor sentinels (unique path signatures) that must appear in the sidebar / chrome
    expect($html)->toContain('viewBox="0 0 256 256"'); // Phosphor SVG signature
    expect($html)->toContain(phosphorPathSignature('phosphor-house')); // Dashboard
    expect($html)->toContain(phosphorPathSignature('phosphor-list-bullets')); // View Log

    // Nav-specific Heroicon names we migrated away from must not appear
    foreach ([
        'name="o-home"',
        'icon="o-home"',
        'icon="o-queue-list"',
        'icon="o-user-group"',
        'icon="o-cog-6-tooth"',
        'icon="o-book-open"',
        'icon="o-calendar-days"',
        'icon="o-wrench-screwdriver"',
        'icon="o-signal"',
    ] as $needle) {
        expect($html)->not->toContain($needle);
    }
});

test('guest home page renders Phosphor nav icons', function () {
    $response = $this->followingRedirects()->get('/');
    $response->assertOk();

    $html = $response->getContent();
    expect($html)->toContain('viewBox="0 0 256 256"');
    expect($html)->toContain(phosphorPathSignature('phosphor-house')); // Home
    expect($html)->toContain(phosphorPathSignature('phosphor-list-bullets')); // View Log
});
