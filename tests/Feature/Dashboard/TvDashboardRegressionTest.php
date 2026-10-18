<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Dashboard\Widgets\Chart;
use App\Livewire\Dashboard\Widgets\ListWidget;
use App\Livewire\Dashboard\Widgets\ProgressBar;
use App\Livewire\Dashboard\Widgets\SectionsWorked;
use App\Livewire\Dashboard\Widgets\StatCard;
use App\Livewire\Dashboard\Widgets\Timer;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\SectionSeeder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
});

/**
 * Create an in-progress event that started the given number of minutes ago.
 *
 * @return array{event: Event, config: EventConfiguration, session: OperatingSession}
 */
function startedEvent(int $minutesAgo = 150): array
{
    $event = Event::factory()->create([
        'start_time' => appNow()->subMinutes($minutesAgo),
        'end_time' => appNow()->addHours(20),
    ]);
    $config = EventConfiguration::factory()->for($event)->create();
    $station = Station::factory()->create(['event_configuration_id' => $config->id]);
    $session = OperatingSession::factory()->create(['station_id' => $station->id]);

    return ['event' => $event, 'config' => $config, 'session' => $session];
}

describe('formatTimeAgo helper', function () {
    it('reports the age of past timestamps', function () {
        expect(formatTimeAgo(appNow()->subSeconds(30)))->toBe('just now')
            ->and(formatTimeAgo(appNow()->subMinutes(5)))->toBe('5m ago')
            ->and(formatTimeAgo(appNow()->subHours(3)))->toBe('3h ago')
            ->and(formatTimeAgo(appNow()->subDays(2)))->toBe('2d ago')
            ->and(formatTimeAgo(null))->toBe('never');
    });
});

describe('updated-at footer component', function () {
    it('renders server-side age plus the timestamps the client ticks from', function () {
        $html = Blade::render('<x-dashboard.updated-at :at="$at" />', ['at' => appNow()->subMinutes(5)]);

        expect($html)
            ->toContain('x-data="updatedAgo"')
            ->toContain('data-updated-at="')
            ->toContain('data-server-now="')
            ->toContain('5m ago');
    });
});

describe('chart widget', function () {
    it('computes the hourly rate from elapsed time, not hour buckets', function () {
        ['config' => $config, 'session' => $session] = startedEvent(150);

        Contact::factory()->count(150)->for($config, 'eventConfiguration')->create([
            'operating_session_id' => $session->id,
            'qso_time' => appNow()->subMinutes(30),
        ]);

        $chartData = Livewire::test(Chart::class, [
            'config' => ['data_source' => 'qsos_per_hour', 'time_range' => 'event'],
        ])->viewData('chartData');

        // 150 QSOs over 2.5 hours = 60/hr (the old bucket count would divide by 3 or 4).
        expect($chartData['summary_value'])->toBe('60.0/hr');
    });

    it('mirrors fresh chart data to the client on every render', function () {
        ['config' => $config, 'session' => $session] = startedEvent();

        $component = Livewire::test(Chart::class, [
            'config' => ['data_source' => 'qsos_per_band'],
        ]);

        expect($component->get('chartPayload'))
            ->not->toHaveKey('last_updated_at')
            ->and($component->get('chartPayload')['labels'])->toBe([]);

        Contact::factory()->count(4)->for($config, 'eventConfiguration')->create([
            'operating_session_id' => $session->id,
        ]);
        Cache::flush();

        $component->call('$refresh');

        expect(array_sum($component->get('chartPayload')['datasets'][0]['data']))->toBe(4);
    });
});

describe('progress bar widget', function () {
    it('measures milestone progress from the previous milestone', function () {
        $widget = new ProgressBar;

        expect($widget->calculateMilestonePercentage(37, 50))->toBe(74.0)
            ->and($widget->calculateMilestonePercentage(132, 150))->toBe(64.0)
            ->and($widget->calculateMilestonePercentage(150, 200))->toBe(0.0)
            ->and($widget->calculateMilestonePercentage(1049, 1050))->toBe(98.0);
    });

    it('scales the class target by the configured transmitter count', function () {
        $event = Event::factory()->create([
            'start_time' => appNow()->subHour(),
            'end_time' => appNow()->addHours(23),
        ]);
        EventConfiguration::factory()->for($event)->create(['transmitter_count' => 3]);

        $data = Livewire::test(ProgressBar::class, ['config' => ['metric' => 'class_target']])
            ->viewData('data');

        expect($data['target'])->toBe(600)
            ->and($data['footer_label'])->toMatch('/^Toward Class 3[A-Z] target$/');
    });

    it('wires the milestone interval into the Alpine component', function () {
        Livewire::test(ProgressBar::class)
            ->assertSeeHtml('x-data="progressBarWidget(true, 50)"');
    });
});

describe('stat card widget', function () {
    it('does not count operators whose only contacts are duplicates', function () {
        ['config' => $config, 'session' => $session] = startedEvent();
        $dupeOnlySession = OperatingSession::factory()->create([
            'station_id' => $session->station_id,
            'operator_user_id' => User::factory()->create()->id,
        ]);

        Contact::factory()->for($config, 'eventConfiguration')->create(['operating_session_id' => $session->id]);
        Contact::factory()->for($config, 'eventConfiguration')->create([
            'operating_session_id' => $dupeOnlySession->id,
            'is_duplicate' => true,
        ]);

        $data = Livewire::test(StatCard::class, ['config' => ['metric' => 'operators_count']])
            ->viewData('data');

        expect($data['value'])->toBe('1');
    });

    it('skips trend bookkeeping when the trend is hidden', function () {
        ['config' => $config] = startedEvent();
        $widgetConfig = ['metric' => 'qso_count', 'show_trend' => false];
        $historicalKey = 'dashboard:widget:StatCard:'.hash('xxh128', json_encode($widgetConfig)).":{$config->id}:history:1h";

        Livewire::test(StatCard::class, ['config' => $widgetConfig]);

        expect(Cache::has($historicalKey))->toBeFalse();
    });

    it('renders the value server-side before Alpine animates it', function () {
        startedEvent();

        Livewire::test(StatCard::class, ['config' => ['metric' => 'qso_count'], 'size' => 'tv'])
            ->assertSeeHtml('x-text="formattedValue"');
    });
});

describe('widgets that previously never refreshed', function () {
    it('highlights only the contacts that arrived since the previous render', function () {
        ['config' => $config, 'session' => $session] = startedEvent();
        Contact::factory()->count(3)->for($config, 'eventConfiguration')->create([
            'operating_session_id' => $session->id,
            'qso_time' => appNow()->subMinutes(10),
        ]);

        $component = Livewire::test(ListWidget::class, ['config' => ['list_type' => 'recent_contacts'], 'size' => 'tv']);

        // Nothing is "new" on the first paint.
        expect($component->viewData('newItemKeys'))->toBe([]);

        $newContact = Contact::factory()->for($config, 'eventConfiguration')->create([
            'operating_session_id' => $session->id,
            'qso_time' => appNow(),
        ]);
        Cache::flush();
        $component->call('$refresh');

        expect($component->viewData('newItemKeys'))->toBe(["contact-{$newContact->id}"]);
        $component->assertSeeHtml('wire:key="list-item-contact-'.$newContact->id.'"');
        expect(substr_count($component->html(), 'dashboard-item-new'))->toBe(1);

        // The highlight lasts one render only.
        Cache::flush();
        $component->call('$refresh');

        expect($component->viewData('newItemKeys'))->toBe([]);
    });

    it('polls the recent contacts list', function () {
        startedEvent();

        Livewire::test(ListWidget::class, ['config' => ['list_type' => 'recent_contacts'], 'size' => 'tv'])
            ->assertSeeHtml('wire:poll.visible.15s');
    });

    it('polls sections worked and renders a non-interactive TV board', function () {
        $this->seed(SectionSeeder::class);
        startedEvent();

        Livewire::test(SectionsWorked::class, ['size' => 'tv'])
            ->assertSeeHtml('wire:poll.visible.30s')
            ->assertSee(['W1', 'W0', 'KH6/KL7/KP4', 'VE', 'DX'])
            ->assertDontSeeHtml('openArea');
    });

    it('re-keys the countdown by phase and polls for phase changes', function () {
        $event = Event::factory()->create([
            'setup_allowed_from' => appNow()->subHour(),
            'start_time' => appNow()->addHour(),
            'end_time' => appNow()->addHours(25),
        ]);

        Livewire::test(Timer::class, ['size' => 'tv'])
            ->assertSee('Event Starts In')
            ->assertSeeHtml('wire:poll.visible.60s')
            ->assertSeeHtml('wire:key="countdown-'.$event->start_time->toIso8601String().'-Event Starts In"');
    });
});
