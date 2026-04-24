<?php

use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Scoring\Bonuses\FieldDay2025\EducationalActivityStrategy;
use App\Scoring\Bonuses\FieldDay2025\PublicInfoBoothStrategy;
use App\Scoring\Bonuses\FieldDay2025\PublicLocationStrategy;
use App\Scoring\Bonuses\FieldDay2025\SocialMediaStrategy;
use App\Scoring\Bonuses\FieldDay2025\WebSubmissionStrategy;

dataset('manualStrategies', [
    [SocialMediaStrategy::class, 'social_media'],
    [PublicLocationStrategy::class, 'public_location'],
    [PublicInfoBoothStrategy::class, 'public_info_booth'],
    [EducationalActivityStrategy::class, 'educational_activity'],
    [WebSubmissionStrategy::class, 'web_submission'],
]);

it('manual strategy reports correct code and trigger type and does nothing on reconcile', function (string $class, string $code) {
    $event = Event::factory()->create(['rules_version' => '2025']);
    $config = EventConfiguration::factory()->for($event)->create();

    $strategy = new $class;
    expect($strategy->code())->toBe($code)
        ->and($strategy->triggerType())->toBe('manual')
        ->and($strategy->subscribesTo())->toBe([]);

    $strategy->reconcile($config);

    expect(EventBonus::where('event_configuration_id', $config->id)->count())->toBe(0);
})->with('manualStrategies');
