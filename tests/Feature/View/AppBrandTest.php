<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\View\Components\AppBrand;

test('the brand shows the event the app is running, not the first active config row', function () {
    $past = Event::factory()->create(['start_time' => now()->subYear(), 'end_time' => now()->subYear()->addDay()]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $past->id, 'is_active' => true]);

    $current = Event::factory()->create(['start_time' => now()->subHour(), 'end_time' => now()->addDay()]);
    $currentConfig = EventConfiguration::factory()->create(['event_id' => $current->id, 'is_active' => true]);

    expect((new AppBrand)->activeEvent?->id)->toBe($currentConfig->id)
        ->not->toBe($pastConfig->id);
});
