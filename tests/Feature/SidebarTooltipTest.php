<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('sidebar tooltip portal element is rendered in the layout', function () {
    // Mark setup as complete
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true']
    );

    $user = User::factory()->create();

    $response = $this->actingAs($user)->followingRedirects()->get('/');

    $response->assertOk();
    $response->assertSee('id="sidebar-tooltip"', false);
    $response->assertSee('sidebar-tooltip::before', false);
    $response->assertSee('style="display:none"', false);
    $response->assertSeeInOrder([
        '#sidebar-tooltip',
        'sidebar-tooltip::before',
        'id="sidebar-tooltip"',
        'style="display:none"',
    ], false);
});

test('sidebar scroll indicators use opacity instead of x-show to prevent layout jump', function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true']
    );

    $user = User::factory()->create();

    $response = $this->actingAs($user)->followingRedirects()->get('/');

    $response->assertOk();

    // Both scroll indicator buttons should always be in the DOM (opacity-based, not x-show)
    $response->assertSee('aria-label="Scroll up"', false);
    $response->assertSee('aria-label="Scroll down"', false);

    // They should use :class binding with opacity, not x-show
    $response->assertSee("canScrollUp ? 'opacity-100' : 'opacity-0 pointer-events-none'", false);
    $response->assertSee("canScrollDown ? 'opacity-100' : 'opacity-0 pointer-events-none'", false);

    // Ensure x-show is NOT used on scroll indicators (it causes layout jumps)
    $content = $response->getContent();
    preg_match_all('/x-show="canScrollUp"/', $content, $upMatches);
    preg_match_all('/x-show="canScrollDown"/', $content, $downMatches);
    expect($upMatches[0])->toBeEmpty();
    expect($downMatches[0])->toBeEmpty();
});

test('sidebar scrollable area has mouseover handler and tooltip state', function () {
    // Mark setup as complete
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true']
    );

    $user = User::factory()->create();

    $response = $this->actingAs($user)->followingRedirects()->get('/');

    $response->assertOk();
    $response->assertSeeInOrder([
        'tooltipTimer',
        'tooltipEl',
        'isSidebarCollapsed',
        'sidebar-scroll-area',
        '@mouseover',
        'isSidebarCollapsed',
        '@mouseleave',
    ], false);
});
