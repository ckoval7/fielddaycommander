<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Scoring\Contracts\BonusStrategy;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\EventBonusReconciler;
use App\Scoring\RuleSetFactory;

class SpyStrategy implements BonusStrategy
{
    public int $reconcileCount = 0;

    public function __construct(public string $spyCode) {}

    public function code(): string
    {
        return $this->spyCode;
    }

    public function triggerType(): string
    {
        return 'derived';
    }

    public function subscribesTo(): array
    {
        return [];
    }

    public function reconcile(EventConfiguration $config): void
    {
        $this->reconcileCount++;
    }
}

it('reconcileAll calls reconcile on every registered strategy', function () {
    $event = Event::factory()->create(['rules_version' => '2025']);
    $config = EventConfiguration::factory()->for($event)->create();

    $a = new SpyStrategy('a');
    $b = new SpyStrategy('b');

    $fakeRuleSet = Mockery::mock(RuleSet::class);
    $fakeRuleSet->shouldReceive('strategies')->andReturn(['a' => 'A', 'b' => 'B']);

    $factory = Mockery::mock(RuleSetFactory::class);
    $factory->shouldReceive('forEvent')->andReturn($fakeRuleSet);

    app()->bind('A', fn () => $a);
    app()->bind('B', fn () => $b);

    (new EventBonusReconciler($factory))->reconcileAll($config);

    expect($a->reconcileCount)->toBe(1)
        ->and($b->reconcileCount)->toBe(1);
});

it('reconcileOne calls reconcile only on the matching strategy', function () {
    $event = Event::factory()->create(['rules_version' => '2025']);
    $config = EventConfiguration::factory()->for($event)->create();

    $a = new SpyStrategy('a');
    $b = new SpyStrategy('b');

    $fakeRuleSet = Mockery::mock(RuleSet::class);
    $fakeRuleSet->shouldReceive('strategies')->andReturn(['a' => 'A', 'b' => 'B']);

    $factory = Mockery::mock(RuleSetFactory::class);
    $factory->shouldReceive('forEvent')->andReturn($fakeRuleSet);

    app()->bind('A', fn () => $a);
    app()->bind('B', fn () => $b);

    (new EventBonusReconciler($factory))->reconcileOne($config, 'b');

    expect($a->reconcileCount)->toBe(0)
        ->and($b->reconcileCount)->toBe(1);
});

it('is a no-op when the config has no event', function () {
    $event = Event::factory()->create(['rules_version' => '2025']);
    $config = EventConfiguration::factory()->for($event)->create();

    // Delete the event so the relationship resolves to null
    $event->delete();
    $config->refresh();

    $factory = Mockery::mock(RuleSetFactory::class);
    $factory->shouldNotReceive('forEvent');

    (new EventBonusReconciler($factory))->reconcileAll($config);

    expect(true)->toBeTrue();
})->throwsNoExceptions();
