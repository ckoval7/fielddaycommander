<?php

use App\Models\Dashboard;
use App\Models\Event;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    actingAs($this->user);
});

describe('tv dashboard', function () {
    it('loads tv dashboard without errors', function () {
        $dashboard = Dashboard::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'TV Dashboard',
            'config' => [
                [
                    'id' => 'widget-1',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'total_contacts'],
                    'order' => 1,
                    'visible' => true,
                ],
            ],
        ]);

        $page = visit("/dashboard/{$dashboard->id}/tv");

        $page->assertSee('TV Dashboard')
            ->assertNoJavaScriptErrors();
    });

    it('displays widgets in 5-column grid', function () {
        $dashboard = Dashboard::factory()->create([
            'user_id' => $this->user->id,
            'config' => [
                [
                    'id' => 'widget-1',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'total_contacts'],
                    'order' => 1,
                    'visible' => true,
                ],
            ],
        ]);

        $page = visit("/dashboard/{$dashboard->id}/tv");

        $page->assertSeeHtml('grid-template-columns: repeat(5, 1fr)')
            ->assertNoJavaScriptErrors();
    });

    it('shows fullscreen toggle button', function () {
        $dashboard = Dashboard::factory()->create([
            'user_id' => $this->user->id,
            'config' => [
                [
                    'id' => 'widget-1',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'total_contacts'],
                    'order' => 1,
                    'visible' => true,
                ],
            ],
        ]);

        $page = visit("/dashboard/{$dashboard->id}/tv");

        $page->assertSee('Fullscreen (F)')
            ->assertNoJavaScriptErrors();
    });

    it('displays event countdown timer when event is active', function () {
        $event = Event::factory()->create([
            'start_time' => now()->subHours(2),
            'end_time' => now()->addHours(22),
        ]);

        $dashboard = Dashboard::factory()->create([
            'user_id' => $this->user->id,
            'config' => [
                [
                    'id' => 'widget-1',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'total_contacts'],
                    'order' => 1,
                    'visible' => true,
                ],
            ],
        ]);

        $page = visit("/dashboard/{$dashboard->id}/tv");

        // Timer should be visible with clock icon
        $page->assertSeeHtml('o-clock')
            ->assertNoJavaScriptErrors();
    });

    it('hides kiosk mode indicator by default', function () {
        $dashboard = Dashboard::factory()->create([
            'user_id' => $this->user->id,
            'config' => [
                [
                    'id' => 'widget-1',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'total_contacts'],
                    'order' => 1,
                    'visible' => true,
                ],
            ],
        ]);

        $page = visit("/dashboard/{$dashboard->id}/tv");

        $page->assertDontSee('Kiosk Mode')
            ->assertNoJavaScriptErrors();
    });

    it('shows kiosk mode indicator with query parameter', function () {
        $dashboard = Dashboard::factory()->create([
            'user_id' => $this->user->id,
            'config' => [
                [
                    'id' => 'widget-1',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'total_contacts'],
                    'order' => 1,
                    'visible' => true,
                ],
            ],
        ]);

        $page = visit("/dashboard/{$dashboard->id}/tv?kiosk=1");

        $page->assertSee('Kiosk Mode')
            ->assertSee('Press F or ESC to exit')
            ->assertNoJavaScriptErrors();
    });

    it('hides header in kiosk mode', function () {
        $dashboard = Dashboard::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Test Title',
            'config' => [
                [
                    'id' => 'widget-1',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'total_contacts'],
                    'order' => 1,
                    'visible' => true,
                ],
            ],
        ]);

        $page = visit("/dashboard/{$dashboard->id}/tv?kiosk=1");

        // In kiosk mode, header should be hidden via x-show
        $page->assertSeeHtml('x-show="!kiosk"')
            ->assertNoJavaScriptErrors();
    });
});

describe('tv widgets', function () {
    it('renders widgets in tv size', function () {
        $dashboard = Dashboard::factory()->create([
            'user_id' => $this->user->id,
            'config' => [
                [
                    'id' => 'widget-1',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'total_contacts'],
                    'order' => 1,
                    'visible' => true,
                ],
            ],
        ]);

        $page = visit("/dashboard/{$dashboard->id}/tv");

        // TV widgets should have size="tv" prop
        $page->assertSeeHtml('size="tv"')
            ->assertNoJavaScriptErrors();
    });

    it('skips hidden widgets', function () {
        $dashboard = Dashboard::factory()->create([
            'user_id' => $this->user->id,
            'config' => [
                [
                    'id' => 'widget-1',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'total_contacts'],
                    'order' => 1,
                    'visible' => true,
                ],
                [
                    'id' => 'widget-2',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'total_score'],
                    'order' => 2,
                    'visible' => false,
                ],
            ],
        ]);

        $page = visit("/dashboard/{$dashboard->id}/tv");

        $page->assertSeeHtml('wire:key="tv-stat-widget-1"')
            ->assertDontSeeHtml('wire:key="tv-stat-widget-2"')
            ->assertNoJavaScriptErrors();
    });

    it('displays empty state when no widgets configured', function () {
        $dashboard = Dashboard::factory()->create([
            'user_id' => $this->user->id,
            'config' => [],
        ]);

        $page = visit("/dashboard/{$dashboard->id}/tv");

        $page->assertSee('No widgets configured')
            ->assertSee('Configure widgets in the main dashboard to display here')
            ->assertNoJavaScriptErrors();
    });
});
