<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Reports\ReportsIndex;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Permission::firstOrCreate(['name' => 'view-reports']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('view-reports');
});

function activeEventConfiguration(bool $scored): EventConfiguration
{
    $event = ($scored ? Event::factory() : Event::factory()->publicService())->create([
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(6),
    ]);

    return ($scored ? EventConfiguration::factory() : EventConfiguration::factory()->publicService())
        ->create(['event_id' => $event->id]);
}

test('contest-only pages return 404 for an unscored event', function (string $uri) {
    activeEventConfiguration(scored: false);

    $this->actingAs($this->user)->get($uri)->assertNotFound();
})->with([
    'scoring' => '/scoring',
    'section map' => '/section-map',
    'cabrillo' => '/reports/cabrillo',
    'wfd summary' => '/reports/wfd-event-summary',
    'submission sheet' => '/reports/submission-sheet',
    'bulletin' => '/w1aw-bulletin',
]);

test('contest pages stay available for a scored event', function (string $uri) {
    activeEventConfiguration(scored: true);

    $this->actingAs($this->user)->get($uri)->assertOk();
})->with([
    'scoring' => '/scoring',
    'section map' => '/section-map',
    'cabrillo' => '/reports/cabrillo',
]);

test('the ADIF export stays available for an unscored event', function () {
    activeEventConfiguration(scored: false);

    $this->actingAs($this->user)->get('/reports/adif')->assertOk();
});

test('the nav hides scoring links for an unscored event', function () {
    activeEventConfiguration(scored: false);

    $this->actingAs($this->user)->get('/reports')
        ->assertOk()
        ->assertDontSee('href="/scoring"', false)
        ->assertDontSee('href="'.route('section-map').'"', false);
});

test('the nav shows scoring links for a scored event', function () {
    activeEventConfiguration(scored: true);

    $this->actingAs($this->user)->get('/reports')
        ->assertOk()
        ->assertSee('href="/scoring"', false)
        ->assertSee('href="'.route('section-map').'"', false);
});

test('reports hide contest exports for an unscored event', function () {
    activeEventConfiguration(scored: false);

    Livewire::actingAs($this->user)->test(ReportsIndex::class)
        ->assertDontSee(route('reports.cabrillo'))
        ->assertDontSee(route('reports.wfd-event-summary'))
        ->assertDontSee(route('reports.submission-sheet'))
        ->assertDontSee('Sections Worked')
        ->assertSee(route('reports.adif'))
        ->assertSee('Public Service / Other');
});

test('scored-only dashboard widgets are recognised', function () {
    $service = app(DashboardService::class);

    expect($service->isScoredOnly(['type' => 'sections_worked', 'config' => []]))->toBeTrue()
        ->and($service->isScoredOnly(['type' => 'stat_card', 'config' => ['metric' => 'total_score']]))->toBeTrue()
        ->and($service->isScoredOnly(['type' => 'stat_card', 'config' => ['metric' => 'qso_count']]))->toBeFalse()
        ->and($service->isScoredOnly(['type' => 'timer', 'config' => []]))->toBeFalse();
});

test('the TV dashboard drops scored-only widgets for an unscored event', function () {
    activeEventConfiguration(scored: false);

    $widgets = $this->get(route('dashboard.tv'))->assertOk()->viewData('widgets');

    expect($widgets->pluck('type'))->not->toContain('sections_worked')
        ->and($widgets->pluck('config.metric')->filter())->not->toContain('total_score');
});
