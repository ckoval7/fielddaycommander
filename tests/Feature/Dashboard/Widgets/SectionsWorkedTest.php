<?php

use App\Livewire\Dashboard\Widgets\SectionsWorked;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Section;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\SectionSeeder::class);
    $this->seed(\Database\Seeders\BandSeeder::class);
    $this->seed(\Database\Seeders\ModeSeeder::class);
});

describe('SectionsWorked Widget', function () {
    test('renders successfully with no active event', function () {
        Livewire::test(SectionsWorked::class, ['config' => [], 'size' => 'normal'])
            ->assertOk()
            ->assertSee('Sections Worked');
    });

    test('shows zero progress when no contacts exist', function () {
        $event = Event::factory()->create([
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);
        EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $component = Livewire::test(SectionsWorked::class, ['config' => [], 'size' => 'normal']);
        $data = $component->instance()->getData();

        expect($data['total_worked'])->toBe(0);
        expect($data['total_sections'])->toBeGreaterThan(0);
    });

    test('marks sections as worked when contacts exist', function () {
        $event = Event::factory()->create([
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);
        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $ctSection = Section::where('code', 'CT')->first();
        $meSection = Section::where('code', 'ME')->first();

        Contact::factory()->create([
            'event_configuration_id' => $config->id,
            'section_id' => $ctSection->id,
            'is_duplicate' => false,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $config->id,
            'section_id' => $meSection->id,
            'is_duplicate' => false,
        ]);

        $component = Livewire::test(SectionsWorked::class, ['config' => [], 'size' => 'normal']);
        $data = $component->instance()->getData();

        expect($data['total_worked'])->toBe(2);

        $area1 = collect($data['groups'])->firstWhere('label', '1');
        expect($area1['worked_count'])->toBe(2);
        expect($area1['total_count'])->toBe(7);

        $ctBadge = collect($area1['sections'])->firstWhere('code', 'CT');
        expect($ctBadge['worked'])->toBeTrue();

        $nhBadge = collect($area1['sections'])->firstWhere('code', 'NH');
        expect($nhBadge['worked'])->toBeFalse();
    });

    test('excludes duplicate contacts from worked sections', function () {
        $event = Event::factory()->create([
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);
        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $ctSection = Section::where('code', 'CT')->first();

        Contact::factory()->create([
            'event_configuration_id' => $config->id,
            'section_id' => $ctSection->id,
            'is_duplicate' => true,
        ]);

        $component = Livewire::test(SectionsWorked::class, ['config' => [], 'size' => 'normal']);
        $data = $component->instance()->getData();

        expect($data['total_worked'])->toBe(0);
    });

    test('groups KH6 KL7 and KP4 into a single combined group', function () {
        $event = Event::factory()->create([
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);
        EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $component = Livewire::test(SectionsWorked::class, ['config' => [], 'size' => 'normal']);
        $data = $component->instance()->getData();

        $combinedGroup = collect($data['groups'])->firstWhere('label', 'KH6 / KL7 / KP4');
        expect($combinedGroup)->not->toBeNull();
        expect($combinedGroup['total_count'])->toBe(4);

        expect(collect($data['groups'])->firstWhere('label', 'KH6'))->toBeNull();
        expect(collect($data['groups'])->firstWhere('label', 'KL7'))->toBeNull();
        expect(collect($data['groups'])->firstWhere('label', 'KP4'))->toBeNull();
    });

    test('call area labels use numbers without W prefix', function () {
        $event = Event::factory()->create([
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);
        EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $component = Livewire::test(SectionsWorked::class, ['config' => [], 'size' => 'normal']);
        $data = $component->instance()->getData();

        $labels = collect($data['groups'])->pluck('label')->all();

        expect($labels)->toContain('1', '2', '3', '4', '5', '6', '7', '8', '9', '0');
        expect($labels)->not->toContain('W1', 'W2', 'W3');
    });

    test('groups are in correct display order', function () {
        $event = Event::factory()->create([
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);
        EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $component = Livewire::test(SectionsWorked::class, ['config' => [], 'size' => 'normal']);
        $data = $component->instance()->getData();

        $labels = collect($data['groups'])->pluck('label')->all();

        expect($labels)->toBe([
            '1', '2', '3', '4', '5', '6', '7', '8', '9', '0',
            'KH6 / KL7 / KP4', 'VE', 'DX',
        ]);
    });
});
