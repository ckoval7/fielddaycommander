<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Message;
use App\Scoring\Contracts\BonusStrategy;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\DomainEvents\GuestbookEntryChanged;
use App\Scoring\DomainEvents\MessageChanged;
use App\Scoring\Listeners\ReconcileOnDomainEvent;
use App\Scoring\RuleSetFactory;

class Subscriber implements BonusStrategy
{
    public int $calls = 0;

    public function __construct(public string $c, public array $subs) {}

    public function code(): string
    {
        return $this->c;
    }

    public function triggerType(): string
    {
        return 'derived';
    }

    public function subscribesTo(): array
    {
        return $this->subs;
    }

    public function reconcile(EventConfiguration $config): void
    {
        $this->calls++;
    }
}

it('calls reconcile only on strategies that subscribe to the dispatched event', function () {
    $event = Event::factory()->create(['rules_version' => '2025']);
    $config = EventConfiguration::factory()->for($event)->create();
    $message = Message::factory()->create(['event_configuration_id' => $config->id]);

    $listensToMessages = new Subscriber('a', [MessageChanged::class]);
    $listensToGuestbook = new Subscriber('b', [GuestbookEntryChanged::class]);

    $fakeRuleSet = Mockery::mock(RuleSet::class);
    $fakeRuleSet->shouldReceive('strategiesFor')
        ->with(MessageChanged::class)
        ->andReturn(['A']);
    $factory = Mockery::mock(RuleSetFactory::class);
    $factory->shouldReceive('forEvent')->andReturn($fakeRuleSet);

    app()->bind('A', fn () => $listensToMessages);
    app()->bind('B', fn () => $listensToGuestbook);

    (new ReconcileOnDomainEvent($factory, app()))
        ->handle(new MessageChanged($message, $config->id));

    expect($listensToMessages->calls)->toBe(1)
        ->and($listensToGuestbook->calls)->toBe(0);
});

it('is a no-op when eventConfigurationId is null', function () {
    $message = Message::factory()->make(['event_configuration_id' => null]);

    $factory = Mockery::mock(RuleSetFactory::class);
    $factory->shouldNotReceive('forEvent');

    (new ReconcileOnDomainEvent($factory, app()))
        ->handle(new MessageChanged($message, null));
})->throwsNoExceptions();
