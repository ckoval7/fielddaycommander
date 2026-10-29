<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Dashboard\Widgets\SectionsWorked;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Section;
use Database\Seeders\BandSeeder;
use Database\Seeders\ModeSeeder;
use Database\Seeders\SectionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(SectionSeeder::class);
    $this->seed(BandSeeder::class);
    $this->seed(ModeSeeder::class);
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

        $area1 = collect($data['groups'])->firstWhere('label', 'W1');
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

        expect($labels)->toContain('W1', 'W2', 'W3', 'W4', 'W5', 'W6', 'W7', 'W8', 'W9', 'W0');
        expect($labels)->not->toContain('1', '2', '3');
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
            'W1', 'W2', 'W3', 'W4', 'W5', 'W6', 'W7', 'W8', 'W9', 'W0',
            'KH6 / KL7 / KP4', 'VE', 'DX',
        ]);
    });

    test('lists Mexico under the DX group and marks it worked for Winter Field Day', function () {
        $event = Event::factory()->create([
            'event_type_id' => EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day', 'is_active' => true])->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);
        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        Contact::factory()->create([
            'event_configuration_id' => $config->id,
            'section_id' => Section::where('code', 'MX')->value('id'),
            'is_duplicate' => false,
        ]);

        $component = Livewire::test(SectionsWorked::class, ['config' => [], 'size' => 'normal']);
        $dxGroup = collect($component->instance()->getData()['groups'])->firstWhere('label', 'DX');

        expect(collect($dxGroup['sections'])->pluck('code')->all())->toContain('DX', 'MX')
            ->and(collect($dxGroup['sections'])->firstWhere('code', 'MX')['worked'])->toBeTrue()
            ->and($dxGroup['worked_count'])->toBe(1);
    });

    test('lists Yukon under the VE group for Winter Field Day', function () {
        $event = Event::factory()->create([
            'event_type_id' => EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day', 'is_active' => true])->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);
        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        Contact::factory()->create([
            'event_configuration_id' => $config->id,
            'section_id' => Section::where('code', 'YT')->value('id'),
            'is_duplicate' => false,
        ]);

        $component = Livewire::test(SectionsWorked::class, ['config' => [], 'size' => 'normal']);
        $veGroup = collect($component->instance()->getData()['groups'])->firstWhere('label', 'VE');

        expect(collect($veGroup['sections'])->firstWhere('code', 'YT')['worked'])->toBeTrue()
            ->and(collect($veGroup['sections'])->firstWhere('code', 'TER')['worked'])->toBeFalse();
    });

    test('ARRL events fold Mexico into DX and Yukon into TER', function () {
        $event = Event::factory()->create([
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);
        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        foreach (['MX', 'YT'] as $code) {
            Contact::factory()->create([
                'event_configuration_id' => $config->id,
                'section_id' => Section::where('code', $code)->value('id'),
                'is_duplicate' => false,
            ]);
        }

        $component = Livewire::test(SectionsWorked::class, ['config' => [], 'size' => 'normal']);
        $data = $component->instance()->getData();
        $sections = collect($data['groups'])->flatMap(fn (array $group) => $group['sections']);

        expect($sections->pluck('code')->all())->not->toContain('MX', 'YT')
            ->and($sections->firstWhere('code', 'DX')['worked'])->toBeTrue()
            ->and($sections->firstWhere('code', 'TER')['worked'])->toBeTrue()
            ->and($data['total_worked'])->toBe(2)
            ->and($data['total_sections'])->toBe(Section::where('is_active', true)->count() - 2);
    });
});

test('region rows grow to a 44px tap target on touch devices', function () {
    $event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    Livewire::test(SectionsWorked::class, ['config' => [], 'size' => 'normal'])
        ->assertSeeHtml('pointer-coarse:min-h-11');
});

test('reads area counts as "of" so the area name and count stay apart', function () {
    $event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    Livewire::test(SectionsWorked::class, ['config' => [], 'size' => 'normal'])
        ->assertSeeHtml('aria-hidden="true">0 /')
        ->assertSeeHtml('<span class="sr-only">0 of ')
        ->assertSeeHtml('<span class="sr-only">, 0 of ');
});

test('reads call areas phonetically', function () {
    $event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    $component = Livewire::test(SectionsWorked::class, ['config' => [], 'size' => 'normal']);
    $spoken = collect($component->instance()->getData()['groups'])->pluck('spoken_label', 'label');

    expect($spoken['W1'])->toBe('Whiskey One')
        ->and($spoken['KH6 / KL7 / KP4'])->toBe('Kilo Hotel Six, Kilo Lima Seven, Kilo Papa Four')
        ->and($spoken['VE'])->toBe('Victor Echo')
        ->and($spoken['DX'])->toBe('DX');

    $component->assertSeeHtml('<span class="sr-only">Whiskey One</span>');
});
