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
