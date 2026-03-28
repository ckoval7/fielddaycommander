<?php

use App\Livewire\Dashboard\Widgets\ProgressGoals;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingSession;
use Livewire\Livewire;

beforeEach(function () {
    $this->eventType = EventType::create([
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day',
        'is_active' => true,
    ]);
});

// ============================================================================
// PROGRESS GOALS WIDGET TESTS
// ============================================================================

describe('ProgressGoals Widget', function () {
    test('component renders successfully', function () {
        Livewire::test(ProgressGoals::class)
            ->assertOk();
    });

    test('component mounts with tvMode parameter', function () {
        Livewire::test(ProgressGoals::class, ['tvMode' => true])
            ->assertSet('tvMode', true);

        Livewire::test(ProgressGoals::class, ['tvMode' => false])
            ->assertSet('tvMode', false);
    });

    test('component mounts with configurable qsoGoal and scoreGoal', function () {
        Livewire::test(ProgressGoals::class, ['qsoGoal' => 500, 'scoreGoal' => 2500])
            ->assertSet('qsoGoal', 500)
            ->assertSet('scoreGoal', 2500);
    });

    test('component defaults to qsoGoal 1000 and scoreGoal 5000', function () {
        $component = Livewire::test(ProgressGoals::class);

        expect($component->qsoGoal)->toBe(1000);
        expect($component->scoreGoal)->toBe(5000);
    });

    test('component finds active event on mount', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $component = Livewire::test(ProgressGoals::class);

        expect($component->event)->not->toBeNull();
        expect($component->event->id)->toBe($event->id);
    });

    test('component has null event when no active event exists', function () {
        Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->addDays(30),
            'end_time' => appNow()->addDays(31),
        ]);

        $component = Livewire::test(ProgressGoals::class);

        expect($component->event)->toBeNull();
    });

    test('currentQsos returns zero when no event exists', function () {
        $component = Livewire::test(ProgressGoals::class);

        expect($component->currentQsos)->toBe(0);
    });

    test('currentQsos returns zero when event has no configuration', function () {
        Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $component = Livewire::test(ProgressGoals::class);

        expect($component->currentQsos)->toBe(0);
    });

    test('currentQsos returns correct count for active event', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $session = OperatingSession::factory()->create([
            'station_id' => \App\Models\Station::factory()->create([
                'event_configuration_id' => $config->id,
            ])->id,
        ]);

        Contact::factory()->count(20)->create([
            'event_configuration_id' => $config->id,
            'operating_session_id' => $session->id,
            'is_duplicate' => false,
        ]);

        $component = Livewire::test(ProgressGoals::class);

        expect($component->currentQsos)->toBe(20);
    });

    test('currentQsos excludes duplicate contacts', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $session = OperatingSession::factory()->create([
            'station_id' => \App\Models\Station::factory()->create([
                'event_configuration_id' => $config->id,
            ])->id,
        ]);

        Contact::factory()->count(10)->create([
            'event_configuration_id' => $config->id,
            'operating_session_id' => $session->id,
            'is_duplicate' => false,
        ]);

        Contact::factory()->count(5)->create([
            'event_configuration_id' => $config->id,
            'operating_session_id' => $session->id,
            'is_duplicate' => true,
        ]);

        $component = Livewire::test(ProgressGoals::class);

        expect($component->currentQsos)->toBe(10);
    });

    test('currentScore returns zero when no event exists', function () {
        $component = Livewire::test(ProgressGoals::class);

        expect($component->currentScore)->toBe(0);
    });

    test('currentScore returns zero when event has no configuration', function () {
        Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $component = Livewire::test(ProgressGoals::class);

        expect($component->currentScore)->toBe(0);
    });

    test('qsoProgress returns zero when no event exists', function () {
        $component = Livewire::test(ProgressGoals::class);

        expect($component->qsoProgress)->toBe(0.0);
    });

    test('qsoProgress calculates correct percentage', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $session = OperatingSession::factory()->create([
            'station_id' => \App\Models\Station::factory()->create([
                'event_configuration_id' => $config->id,
            ])->id,
        ]);

        // 50 out of 100 QSO goal = 50%
        Contact::factory()->count(50)->create([
            'event_configuration_id' => $config->id,
            'operating_session_id' => $session->id,
            'is_duplicate' => false,
        ]);

        $component = Livewire::test(ProgressGoals::class, ['qsoGoal' => 100]);

        expect($component->qsoProgress)->toBe(50.0);
    });

    test('qsoProgress caps at 100 percent', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $session = OperatingSession::factory()->create([
            'station_id' => \App\Models\Station::factory()->create([
                'event_configuration_id' => $config->id,
            ])->id,
        ]);

        // 120 out of 100 QSO goal = capped at 100%
        Contact::factory()->count(120)->create([
            'event_configuration_id' => $config->id,
            'operating_session_id' => $session->id,
            'is_duplicate' => false,
        ]);

        $component = Livewire::test(ProgressGoals::class, ['qsoGoal' => 100]);

        expect($component->qsoProgress)->toBe(100.0);
    });

    test('qsoProgress returns zero when qsoGoal is zero', function () {
        $component = Livewire::test(ProgressGoals::class, ['qsoGoal' => 0]);

        expect($component->qsoProgress)->toBe(0.0);
    });

    test('scoreProgress returns zero when scoreGoal is zero', function () {
        $component = Livewire::test(ProgressGoals::class, ['scoreGoal' => 0]);

        expect($component->scoreProgress)->toBe(0.0);
    });

    test('qsoStatus returns behind when progress is below 25 percent', function () {
        $component = Livewire::test(ProgressGoals::class);

        // No contacts = 0% = behind
        expect($component->qsoStatus)->toBe('behind');
    });

    test('qsoStatus returns complete when progress is 100 percent', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $session = OperatingSession::factory()->create([
            'station_id' => \App\Models\Station::factory()->create([
                'event_configuration_id' => $config->id,
            ])->id,
        ]);

        Contact::factory()->count(100)->create([
            'event_configuration_id' => $config->id,
            'operating_session_id' => $session->id,
            'is_duplicate' => false,
        ]);

        $component = Livewire::test(ProgressGoals::class, ['qsoGoal' => 100]);

        expect($component->qsoStatus)->toBe('complete');
    });

    test('qsoStatus returns fair when progress is between 25 and 50 percent', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $session = OperatingSession::factory()->create([
            'station_id' => \App\Models\Station::factory()->create([
                'event_configuration_id' => $config->id,
            ])->id,
        ]);

        // 30 out of 100 = 30% = fair
        Contact::factory()->count(30)->create([
            'event_configuration_id' => $config->id,
            'operating_session_id' => $session->id,
            'is_duplicate' => false,
        ]);

        $component = Livewire::test(ProgressGoals::class, ['qsoGoal' => 100]);

        expect($component->qsoStatus)->toBe('fair');
    });

    test('qsoStatus returns good when progress is between 50 and 75 percent', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $session = OperatingSession::factory()->create([
            'station_id' => \App\Models\Station::factory()->create([
                'event_configuration_id' => $config->id,
            ])->id,
        ]);

        // 60 out of 100 = 60% = good
        Contact::factory()->count(60)->create([
            'event_configuration_id' => $config->id,
            'operating_session_id' => $session->id,
            'is_duplicate' => false,
        ]);

        $component = Livewire::test(ProgressGoals::class, ['qsoGoal' => 100]);

        expect($component->qsoStatus)->toBe('good');
    });

    test('qsoStatus returns excellent when progress is between 75 and 100 percent', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $session = OperatingSession::factory()->create([
            'station_id' => \App\Models\Station::factory()->create([
                'event_configuration_id' => $config->id,
            ])->id,
        ]);

        // 80 out of 100 = 80% = excellent
        Contact::factory()->count(80)->create([
            'event_configuration_id' => $config->id,
            'operating_session_id' => $session->id,
            'is_duplicate' => false,
        ]);

        $component = Livewire::test(ProgressGoals::class, ['qsoGoal' => 100]);

        expect($component->qsoStatus)->toBe('excellent');
    });

    test('component eager loads eventConfiguration relationship', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $component = Livewire::test(ProgressGoals::class);

        expect($component->event->relationLoaded('eventConfiguration'))->toBeTrue();
    });
});
