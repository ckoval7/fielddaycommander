<?php

use App\Models\Event;
use App\Scoring\Rules\FieldDay2026;
use App\Scoring\RuleSetFactory;

it('resolves FD-2026 events to FieldDay2026', function () {
    $event = Event::factory()->create(['rules_version' => '2026']);
    expect(app(RuleSetFactory::class)->forEvent($event))->toBeInstanceOf(FieldDay2026::class);
});
