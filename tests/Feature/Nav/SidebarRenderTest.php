<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

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
        'name="phosphor-house"',
        'icon="phosphor-house"',
        'icon="phosphor-list-bullets"',
        'icon="phosphor-users-three"',
        'icon="o-cog-6-tooth"',
        'icon="phosphor-book-open"',
        'icon="phosphor-calendar-dots"',
        'icon="phosphor-wrench"',
        'icon="phosphor-cell-signal-high"',
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

/**
 * @return array<int, string> Titles of the sidebar menu items rendered as active.
 */
function activeMenuTitles(string $html): array
{
    preg_match_all('/<a[^>]*mary-active-menu[^>]*>.*?<\/a>/s', $html, $matches);

    return array_map(fn (string $anchor) => trim(preg_replace('/\s+/', ' ', strip_tags($anchor))), $matches[0]);
}

test('a manage page does not also highlight the nav item whose link prefixes its URL', function () {
    Permission::findOrCreate('manage-shifts');
    $user = User::factory()->create();
    $user->givePermissionTo('manage-shifts');

    $event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);
    Setting::set('active_event_id', $event->id);

    $html = $this->actingAs($user)->get(route('site-safety.manage'))->assertOk()->getContent();

    expect(activeMenuTitles($html))->toBe(['Manage Safety Checklist']);
});

test('sidebar items with an explicit active rule opt out of URL-prefix matching', function () {
    $layout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));

    preg_match_all('/<x-menu-item\b[^>]*?:active="/s', $layout, $items);

    expect($items[0])->not->toBeEmpty();

    foreach ($items[0] as $item) {
        expect($item)->toMatch('/\sexact\s/');
    }
});
