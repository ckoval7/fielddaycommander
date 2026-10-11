<?php

use App\Livewire\Dashboard\DashboardEditor;
use App\Livewire\Dashboard\DashboardManager;
use App\Livewire\Dashboard\Widgets\InfoCard;
use App\Livewire\Dashboard\Widgets\ListWidget;
use App\Livewire\Dashboard\Widgets\StatCard;
use App\Models\Contact;
use App\Models\Dashboard;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\OperatingClass;
use App\Models\User;
use App\Services\DashboardService;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->dashboard = Dashboard::factory()
        ->for($this->user)
        ->default()
        ->create([
            'config' => [
                ['id' => 'stat-1', 'type' => 'stat_card', 'config' => ['metric' => 'qso_count'], 'order' => 0, 'visible' => true],
                ['id' => 'list-1', 'type' => 'list_widget', 'config' => ['list_type' => 'recent_contacts'], 'order' => 1, 'visible' => true],
                ['id' => 'hidden-1', 'type' => 'chart', 'config' => ['data_source' => 'qsos_per_band'], 'order' => 2, 'visible' => false],
            ],
        ]);
});

describe('dashboard ownership', function () {
    it('refuses to copy another user\'s dashboard', function () {
        $victim = User::factory()->create();
        $victimDashboard = Dashboard::factory()->for($victim)->create();

        Livewire::test(DashboardManager::class)
            ->set('newTitle', 'Stolen')
            ->set('copyFrom', $victimDashboard->id)
            ->call('createDashboard')
            ->assertHasErrors(['copyFrom']);

        expect(Dashboard::query()->where('title', 'Stolen')->exists())->toBeFalse()
            ->and($victim->dashboards()->count())->toBe(1);
    });

    it('creates copies in the requesting user\'s account', function () {
        $copy = app(DashboardService::class)->duplicateDashboard($this->dashboard, 'Mine', $this->user);

        expect($copy->user_id)->toBe($this->user->id)
            ->and($copy->is_default)->toBeFalse();
    });

    it('sends the viewer to a dashboard that exists after deleting the one on screen', function () {
        $other = Dashboard::factory()->for($this->user)->create();

        Livewire::test(DashboardManager::class, ['currentDashboardId' => $other->id])
            ->call('confirmDelete', $other->id)
            ->call('deleteDashboard')
            ->assertRedirect(route('dashboard'));

        expect(Dashboard::find($other->id))->toBeNull();
    });
});

describe('widget settings are cleaned on the server', function () {
    it('replaces out-of-range values and drops unknown settings', function () {
        $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
            ->call('enterEditMode')
            ->call('configureWidget', 'list-1')
            ->dispatch('widget-configured', type: 'list_widget', config: [
                'list_type' => 'recent_contacts',
                'item_count' => '100000',
                'evil' => 'x',
            ], mode: 'edit', colSpan: 9, rowSpan: 6);

        $widget = $component->get('widgets')->firstWhere('id', 'list-1');

        expect($widget['config'])->toBe(['list_type' => 'recent_contacts', 'item_count' => '15'])
            ->and($widget['col_span'])->toBe(4)
            ->and($widget['row_span'])->toBe(6);
    });

    it('ignores unknown widget types', function () {
        $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
            ->call('enterEditMode')
            ->call('addWidget', 'not_a_widget');

        expect($component->get('widgets'))->toHaveCount(3);
    });

    it('cleans settings again when saving', function () {
        $saved = app(DashboardService::class)->updateDashboard($this->dashboard, ['config' => [
            ['id' => 'stat-1', 'type' => 'stat_card', 'config' => ['metric' => 'nope', 'show_trend' => 'false'], 'order' => 0, 'visible' => true, 'row_span' => 1],
        ]]);

        expect($saved->config[0]['config'])->toBe(['metric' => 'total_score', 'show_trend' => false])
            ->and($saved->config[0]['row_span'])->toBe(2);
    });

    it('caps list size even for settings saved before cleanup existed', function () {
        $event = Event::factory()->create(['start_time' => appNow()->subHour(), 'end_time' => appNow()->addHours(23)]);
        $config = EventConfiguration::factory()->for($event)->create();
        Contact::factory()->count(60)->for($config, 'eventConfiguration')->create();

        $data = Livewire::test(ListWidget::class, ['config' => ['list_type' => 'recent_contacts', 'item_count' => '100000']])
            ->viewData('data');

        expect($data['items'])->toHaveCount(50);
    });
});

describe('editor behaviour', function () {
    it('reads the widget limit from dashboard.limits', function () {
        config(['dashboard.limits.max_widgets_per_dashboard' => 3]);

        $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
            ->call('enterEditMode')
            ->call('addWidget', 'timer');

        expect($component->get('widgets'))->toHaveCount(3);
    });

    it('restores the saved layout when edit mode is toggled off', function () {
        $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
            ->call('toggleEditMode')
            ->call('resizeWidget', 'stat-1', 3, 6)
            ->call('toggleEditMode');

        $widget = $component->get('widgets')->firstWhere('id', 'stat-1');

        expect($component->get('editMode'))->toBeFalse()
            ->and($widget)->not->toHaveKey('col_span');
    });

    it('changes a widget\'s component key when its settings change', function () {
        $editor = new DashboardEditor;
        $widget = ['id' => 'stat-1', 'config' => ['metric' => 'qso_count']];

        expect($editor->widgetComponentKey($widget))
            ->not->toBe($editor->widgetComponentKey([...$widget, 'config' => ['metric' => 'total_score']]))
            ->toStartWith('stat-1-');
    });

    it('only mounts hidden widgets while editing', function () {
        Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
            ->assertDontSeeHtml('wire:key="chart-hidden-1-')
            ->call('enterEditMode')
            ->assertSeeHtml('wire:key="chart-hidden-1-');
    });

    it('offers the activity feed in the widget picker', function () {
        expect(config('dashboard.widget_types'))->toHaveKey('feed');

        $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
            ->call('enterEditMode')
            ->call('addWidget', 'feed', ['feed_type' => 'milestones_only']);

        expect($component->get('widgets')->last())
            ->type->toBe('feed')
            ->config->toBe(['feed_type' => 'milestones_only']);
    });
});

describe('stat card rates', function () {
    it('stops QSOs per hour at the event end', function () {
        $this->freezeSecond();
        $event = Event::factory()->create(['start_time' => appNow()->subHours(30), 'end_time' => appNow()->subHours(6)]);
        $config = EventConfiguration::factory()->for($event)->create();
        Contact::factory()->count(48)->for($config, 'eventConfiguration')->create();

        $data = Livewire::test(StatCard::class, ['config' => ['metric' => 'qso_per_hour', 'show_trend' => false]])
            ->viewData('data');

        // 48 QSOs over the 24-hour event, not over the 30 hours since it started.
        expect($data['value'])->toBe('2.0');
    });

    it('averages the 4-hour rate over the operating time actually in the window', function () {
        $this->freezeSecond();
        $event = Event::factory()->create(['start_time' => appNow()->subHour(), 'end_time' => appNow()->addHours(23)]);
        $config = EventConfiguration::factory()->for($event)->create();
        Contact::factory()->count(30)->for($config, 'eventConfiguration')->create(['qso_time' => appNow()->subMinutes(30)]);

        $data = Livewire::test(StatCard::class, ['config' => ['metric' => 'avg_qso_rate_4h', 'show_trend' => false]])
            ->viewData('data');

        expect($data['value'])->toBe('30.0');
    });
});

describe('info card', function () {
    it('shows the entry class with its transmitter count', function () {
        $event = Event::factory()->create(['start_time' => appNow()->subHour(), 'end_time' => appNow()->addHours(23)]);
        $operatingClass = OperatingClass::factory()->create(['code' => 'A', 'name' => 'Club']);
        EventConfiguration::factory()->for($event)->create(['operating_class_id' => $operatingClass->id, 'transmitter_count' => 3]);

        $rows = collect(Livewire::test(InfoCard::class, ['config' => ['info_type' => 'event_details']])->viewData('data')['rows']);

        expect($rows->firstWhere('label', 'Class')['value'])->toBe('3A');
    });
});
