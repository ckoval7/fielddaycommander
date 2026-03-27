<?php

use App\Livewire\Dashboard\Widgets\ProgressBar;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    actingAs($this->user);
    Cache::flush();
});

it('implements IsWidget trait', function () {
    expect(ProgressBar::class)
        ->toUseTraits(['App\Livewire\Dashboard\Widgets\Concerns\IsWidget']);
});

it('renders successfully', function () {
    Livewire::test(ProgressBar::class)
        ->assertOk()
        ->assertViewIs('livewire.dashboard.widgets.progress-bar');
});

describe('getData method', function () {
    it('returns default data when no active event exists', function () {
        $widget = Livewire::test(ProgressBar::class);
        $data = $widget->instance()->getData();

        expect($data)->toMatchArray([
            'current' => 0,
            'target' => 50,
            'percentage' => 0.0,
            'label' => '0/50 QSOs to next milestone',
        ]);
    });

    it('returns data for active event with zero contacts', function () {
        $event = Event::factory()->create([
            'start_time' => now()->subHours(1),
            'end_time' => now()->addHours(23),
        ]);
        EventConfiguration::factory()->for($event)->create();

        $widget = Livewire::test(ProgressBar::class);
        $data = $widget->instance()->getData();

        expect($data)->toMatchArray([
            'current' => 0,
            'target' => 50,
            'percentage' => 0.0,
            'label' => '0/50 QSOs to next milestone',
        ]);
    });

    it('calculates progress for event with contacts', function () {
        $event = Event::factory()->create([
            'start_time' => now()->subHours(1),
            'end_time' => now()->addHours(23),
        ]);
        $config = EventConfiguration::factory()->for($event)->create();

        // Create 37 contacts
        Contact::factory()->count(37)->for($config, 'eventConfiguration')->create();

        $widget = Livewire::test(ProgressBar::class);
        $data = $widget->instance()->getData();

        expect($data)->toMatchArray([
            'current' => 37,
            'target' => 50,
            'percentage' => 74.0, // (37-0)/(50-0) * 100 = 74%
            'label' => '37/50 QSOs to next milestone',
        ]);
    });

    it('excludes duplicate contacts from count', function () {
        $event = Event::factory()->create([
            'start_time' => now()->subHours(1),
            'end_time' => now()->addHours(23),
        ]);
        $config = EventConfiguration::factory()->for($event)->create();

        // Create 30 valid contacts
        Contact::factory()->count(30)->for($config, 'eventConfiguration')->create();

        // Create 5 duplicate contacts (should be excluded)
        Contact::factory()->count(5)->for($config, 'eventConfiguration')->create([
            'is_duplicate' => true,
        ]);

        $widget = Livewire::test(ProgressBar::class);
        $data = $widget->instance()->getData();

        expect($data['current'])->toBe(30);
    });

    it('caches results for 3 seconds', function () {
        $event = Event::factory()->create([
            'start_time' => now()->subHours(1),
            'end_time' => now()->addHours(23),
        ]);
        $config = EventConfiguration::factory()->for($event)->create();
        Contact::factory()->count(10)->for($config, 'eventConfiguration')->create();

        $widget = Livewire::test(ProgressBar::class);

        // First call should hit database
        $data1 = $widget->instance()->getData();
        expect($data1['current'])->toBe(10);

        // Add more contacts
        Contact::factory()->count(5)->for($config, 'eventConfiguration')->create();

        // Second call within 3 seconds should return cached value
        $data2 = $widget->instance()->getData();
        expect($data2['current'])->toBe(10); // Still cached

        // Clear cache and verify fresh data
        Cache::flush();
        $data3 = $widget->instance()->getData();
        expect($data3['current'])->toBe(15);
    });

    it('detects milestone achievements', function () {
        $event = Event::factory()->create([
            'start_time' => now()->subHours(1),
            'end_time' => now()->addHours(23),
        ]);
        $config = EventConfiguration::factory()->for($event)->create();
        $station = Station::factory()->create(['event_configuration_id' => $config->id]);
        $session = OperatingSession::factory()->create(['station_id' => $station->id]);
        $contactAttrs = ['operating_session_id' => $session->id];

        $widget = Livewire::test(ProgressBar::class);

        // Not a milestone: 0 QSOs
        $data = $widget->instance()->getData();
        expect($data['is_milestone'])->toBe(false);

        // Not a milestone: 37 QSOs
        Cache::flush();
        Contact::factory()->count(37)->for($config, 'eventConfiguration')->create($contactAttrs);
        $data = $widget->instance()->getData();
        expect($data['is_milestone'])->toBe(false);

        // Milestone: 50 QSOs
        Cache::flush();
        Contact::factory()->count(13)->for($config, 'eventConfiguration')->create($contactAttrs);
        $data = $widget->instance()->getData();
        expect($data['is_milestone'])->toBe(true);

        // Not a milestone: 75 QSOs
        Cache::flush();
        Contact::factory()->count(25)->for($config, 'eventConfiguration')->create($contactAttrs);
        $data = $widget->instance()->getData();
        expect($data['is_milestone'])->toBe(false);

        // Milestone: 100 QSOs
        Cache::flush();
        Contact::factory()->count(25)->for($config, 'eventConfiguration')->create($contactAttrs);
        $data = $widget->instance()->getData();
        expect($data['is_milestone'])->toBe(true);
    });
});

describe('milestone calculation logic', function () {
    it('calculates next milestone for zero QSOs', function () {
        $widget = Livewire::test(ProgressBar::class)->instance();
        $milestone = $widget->calculateNextMilestone(0);

        expect($milestone)->toBe(50);
    });

    it('calculates next milestone before first milestone', function () {
        $widget = Livewire::test(ProgressBar::class)->instance();

        expect($widget->calculateNextMilestone(1))->toBe(50);
        expect($widget->calculateNextMilestone(25))->toBe(50);
        expect($widget->calculateNextMilestone(37))->toBe(50);
        expect($widget->calculateNextMilestone(49))->toBe(50);
    });

    it('calculates next milestone when exactly on milestone', function () {
        $widget = Livewire::test(ProgressBar::class)->instance();

        expect($widget->calculateNextMilestone(50))->toBe(100);
        expect($widget->calculateNextMilestone(100))->toBe(150);
        expect($widget->calculateNextMilestone(150))->toBe(200);
        expect($widget->calculateNextMilestone(200))->toBe(250);
    });

    it('calculates next milestone between milestones', function () {
        $widget = Livewire::test(ProgressBar::class)->instance();

        expect($widget->calculateNextMilestone(51))->toBe(100);
        expect($widget->calculateNextMilestone(75))->toBe(100);
        expect($widget->calculateNextMilestone(99))->toBe(100);

        expect($widget->calculateNextMilestone(101))->toBe(150);
        expect($widget->calculateNextMilestone(125))->toBe(150);
        expect($widget->calculateNextMilestone(149))->toBe(150);

        expect($widget->calculateNextMilestone(187))->toBe(200);
        expect($widget->calculateNextMilestone(199))->toBe(200);
    });

    it('handles large QSO counts', function () {
        $widget = Livewire::test(ProgressBar::class)->instance();

        expect($widget->calculateNextMilestone(500))->toBe(550);
        expect($widget->calculateNextMilestone(999))->toBe(1000);
        expect($widget->calculateNextMilestone(1234))->toBe(1250);
    });
});

describe('percentage calculation logic', function () {
    it('calculates 0% for zero QSOs', function () {
        $widget = Livewire::test(ProgressBar::class)->instance();
        $percentage = $widget->calculatePercentage(0, 50);

        expect($percentage)->toBe(0.0);
    });

    it('calculates 100% when current equals target', function () {
        $widget = Livewire::test(ProgressBar::class)->instance();

        expect($widget->calculatePercentage(50, 50))->toBe(100.0);
        expect($widget->calculatePercentage(100, 100))->toBe(100.0);
        expect($widget->calculatePercentage(150, 150))->toBe(100.0);
    });

    it('calculates percentage before first milestone', function () {
        $widget = Livewire::test(ProgressBar::class)->instance();

        expect($widget->calculatePercentage(25, 50))->toBe(50.0);
        expect($widget->calculatePercentage(37, 50))->toBe(74.0);
        expect($widget->calculatePercentage(12, 50))->toBe(24.0);
    });

    it('calculates percentage as current/target ratio', function () {
        $widget = Livewire::test(ProgressBar::class)->instance();

        // 53 of 100 = 53%
        expect($widget->calculatePercentage(53, 100))->toBe(53.0);

        // 75 of 100 = 75%
        expect($widget->calculatePercentage(75, 100))->toBe(75.0);
        expect($widget->calculatePercentage(100, 100))->toBe(100.0);

        // 125 of 150 = 83.3%
        expect($widget->calculatePercentage(125, 150))->toBe(83.3);
        expect($widget->calculatePercentage(150, 150))->toBe(100.0);

        // 187 of 200 = 93.5%
        expect($widget->calculatePercentage(187, 200))->toBe(93.5);
        expect($widget->calculatePercentage(175, 200))->toBe(87.5);
    });

    it('rounds percentage to one decimal place', function () {
        $widget = Livewire::test(ProgressBar::class)->instance();

        // 13/50 = 26%
        expect($widget->calculatePercentage(13, 50))->toBe(26.0);

        // 123/150 = 82%
        expect($widget->calculatePercentage(123, 150))->toBe(82.0);

        // 187/200 = 93.5%
        expect($widget->calculatePercentage(187, 200))->toBe(93.5);

        // Test rounding with 1 decimal
        expect($widget->calculatePercentage(33, 100))->toBe(33.0);
        expect($widget->calculatePercentage(1, 3))->toBe(33.3); // 33.333... rounds to 33.3
    });

    it('handles edge case of zero target', function () {
        $widget = Livewire::test(ProgressBar::class)->instance();
        $percentage = $widget->calculatePercentage(10, 0);

        expect($percentage)->toBe(0.0);
    });
});

describe('rendering and display', function () {
    it('displays current and target numbers', function () {
        $event = Event::factory()->create([
            'start_time' => now()->subHours(1),
            'end_time' => now()->addHours(23),
        ]);
        $config = EventConfiguration::factory()->for($event)->create();
        Contact::factory()->count(37)->for($config, 'eventConfiguration')->create();

        Livewire::test(ProgressBar::class)
            ->assertSee('37')
            ->assertSee('50')
            ->assertSee('74'); // percentage
    });

    it('displays progress bar with correct structure', function () {
        $event = Event::factory()->create([
            'start_time' => now()->subHours(1),
            'end_time' => now()->addHours(23),
        ]);
        $config = EventConfiguration::factory()->for($event)->create();
        Contact::factory()->count(25)->for($config, 'eventConfiguration')->create();

        Livewire::test(ProgressBar::class)
            ->assertSee('50') // target
            ->assertSeeHtml('x-text="displayCurrent"') // Alpine binding for current
            ->assertSeeHtml('x-text="displayPercentage"') // Alpine binding for percentage
            ->assertSeeHtml('%'); // percentage symbol
    });

    it('displays milestone label', function () {
        Livewire::test(ProgressBar::class)
            ->assertSee('To next milestone');
    });

    it('applies TV size styling when size is tv', function () {
        Livewire::test(ProgressBar::class, ['size' => 'tv'])
            ->assertSeeHtml('text-4xl') // Larger numbers
            ->assertSeeHtml('h-8'); // Larger progress bar
    });

    it('applies normal size styling by default', function () {
        Livewire::test(ProgressBar::class)
            ->assertSeeHtml('text-2xl') // Standard numbers
            ->assertSeeHtml('h-4'); // Standard progress bar
    });

    it('includes celebration overlay elements', function () {
        Livewire::test(ProgressBar::class)
            ->assertSeeHtml('x-show="isCelebrating"') // Celebration overlay
            ->assertSeeHtml('Milestone!') // Celebration message
            ->assertSeeHtml('QSOs logged!'); // QSO count message
    });

    it('includes Alpine.js celebration logic', function () {
        Livewire::test(ProgressBar::class)
            ->assertSeeHtml('isCelebrating: false') // Alpine data
            ->assertSeeHtml('lastMilestone: 0') // Milestone tracking
            ->assertSeeHtml('celebrate(current)'); // Celebration function
    });

    it('includes pulse-glow animation class', function () {
        Livewire::test(ProgressBar::class)
            ->assertSeeHtml('animate-pulse-glow'); // CSS animation class
    });
});

describe('widget integration', function () {
    it('returns empty listeners array', function () {
        $widget = Livewire::test(ProgressBar::class)->instance();
        $listeners = $widget->getWidgetListeners();

        expect($listeners)->toBeArray()->toBeEmpty();
    });

    it('accepts widget configuration', function () {
        Livewire::test(ProgressBar::class, [
            'config' => ['metric' => 'next_milestone'],
            'size' => 'normal',
            'widgetId' => 'test-widget-123',
        ])
            ->assertSet('config', ['metric' => 'next_milestone'])
            ->assertSet('size', 'normal')
            ->assertSet('widgetId', 'test-widget-123');
    });

    it('generates cache key including event id', function () {
        $event = Event::factory()->create([
            'start_time' => now()->subHours(1),
            'end_time' => now()->addHours(23),
        ]);

        $widget = Livewire::test(ProgressBar::class, [
            'config' => ['metric' => 'next_milestone'],
        ])->instance();

        $cacheKey = $widget->cacheKey();

        expect($cacheKey)
            ->toContain('ProgressBar')
            ->toContain((string) $event->id);
    });
});

describe('real-world scenarios', function () {
    it('tracks progress through first milestone correctly', function () {
        $event = Event::factory()->create([
            'start_time' => now()->subHours(1),
            'end_time' => now()->addHours(23),
        ]);
        $config = EventConfiguration::factory()->for($event)->create();
        $station = Station::factory()->create(['event_configuration_id' => $config->id]);
        $session = OperatingSession::factory()->create(['station_id' => $station->id]);
        $contactAttrs = ['operating_session_id' => $session->id];

        $widget = Livewire::test(ProgressBar::class)->instance();

        // Start: 0 QSOs
        expect($widget->getData())->toMatchArray([
            'current' => 0,
            'target' => 50,
            'percentage' => 0.0,
        ]);

        // Add 25 QSOs (halfway to 50)
        Cache::flush();
        Contact::factory()->count(25)->for($config, 'eventConfiguration')->create($contactAttrs);
        expect($widget->getData())->toMatchArray([
            'current' => 25,
            'target' => 50,
            'percentage' => 50.0, // 25/50 = 50%
        ]);

        // Reach first milestone - target advances to 100
        Cache::flush();
        Contact::factory()->count(25)->for($config, 'eventConfiguration')->create($contactAttrs);
        expect($widget->getData())->toMatchArray([
            'current' => 50,
            'target' => 100,
            'percentage' => 50.0, // 50/100 = 50%
        ]);
    });

    it('tracks progress through multiple milestones', function () {
        $event = Event::factory()->create([
            'start_time' => now()->subHours(1),
            'end_time' => now()->addHours(23),
        ]);
        $config = EventConfiguration::factory()->for($event)->create();
        $station = Station::factory()->create(['event_configuration_id' => $config->id]);
        $session = OperatingSession::factory()->create(['station_id' => $station->id]);

        $widget = Livewire::test(ProgressBar::class)->instance();

        // 187 QSOs scenario from requirements
        Cache::flush();
        Contact::factory()->count(187)->for($config, 'eventConfiguration')->create([
            'operating_session_id' => $session->id,
        ]);

        $data = $widget->getData();

        expect($data['current'])->toBe(187);
        expect($data['target'])->toBe(200);
        expect($data['percentage'])->toBe(93.5); // 187/200 * 100 = 93.5%
        expect($data['label'])->toBe('187/200 QSOs to next milestone');
    });
});
