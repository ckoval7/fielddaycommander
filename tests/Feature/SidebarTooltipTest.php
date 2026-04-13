<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('sidebar tooltip portal element is rendered in the layout', function () {
    // Mark setup as complete
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true']
    );

    $user = User::factory()->create();
    Event::factory()->create([
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(2),
    ]);

    $response = $this->actingAs($user)->followingRedirects()->get('/');

    $response->assertOk();
    $response->assertSee('id="sidebar-tooltip"', false);
    $response->assertSee('sidebar-tooltip::before', false);
});

test('sidebar scrollable area has mouseover handler for tooltips', function () {
    // Mark setup as complete
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true']
    );

    $user = User::factory()->create();
    Event::factory()->create([
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(2),
    ]);

    $response = $this->actingAs($user)->followingRedirects()->get('/');

    $response->assertOk();
    $response->assertSee('sidebar-scroll-area');
    $response->assertSee('@mouseover');
});
