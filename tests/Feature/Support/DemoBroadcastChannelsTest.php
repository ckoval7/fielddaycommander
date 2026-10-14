<?php

use App\Events\WeatherAlertChanged;
use App\Livewire\Components\WeatherAlertBanner;
use App\Support\BroadcastChannels;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

const VISITOR_A = 'demo_aaaaaaaa_aaaa_4aaa_8aaa_aaaaaaaaaaaa';
const VISITOR_B = 'demo_bbbbbbbb_bbbb_4bbb_8bbb_bbbbbbbbbbbb';

beforeEach(function () {
    Config::set('demo.enabled', true);
    Config::set('database.connections.demo.database', VISITOR_A);
});

/**
 * Check a channel name against a pattern with the same rules as Laravel's broadcaster.
 */
function demoChannelMatchesPattern(string $channelName, string $pattern): bool
{
    $broadcaster = new NullBroadcaster;
    $matches = new ReflectionMethod($broadcaster, 'channelNameMatchesPattern');

    return (bool) $matches->invoke($broadcaster, $channelName, $pattern);
}

test('scopes channel names to the active visitor without exposing the database name', function () {
    $channel = BroadcastChannels::event(1);

    expect($channel)->toStartWith('demo.')
        ->toEndWith('.event.1')
        ->not->toContain(VISITOR_A);

    Config::set('database.connections.demo.database', VISITOR_B);

    expect(BroadcastChannels::event(1))->not->toBe($channel);
});

test('scoped channel names match their scoped authorization patterns', function () {
    expect(demoChannelMatchesPattern(BroadcastChannels::event(1), BroadcastChannels::event('{eventId}')))->toBeTrue()
        ->and(demoChannelMatchesPattern(BroadcastChannels::user(3), BroadcastChannels::user('{id}')))->toBeTrue()
        ->and(demoChannelMatchesPattern(BroadcastChannels::externalLogger(2), BroadcastChannels::externalLogger('{eventConfigId}')))->toBeTrue()
        ->and(demoChannelMatchesPattern(BroadcastChannels::externalLogger(2), BroadcastChannels::event('{eventId}')))->toBeFalse();
});

test('leaves channels unscoped outside a visitor database', function () {
    Config::set('database.connections.demo.database', 'demo_base');

    expect(BroadcastChannels::event(1))->toBe('event.1')
        ->and(BroadcastChannels::weather())->toBe('weather');
});

test('leaves channels unscoped when demo mode is disabled', function () {
    Config::set('demo.enabled', false);

    expect(BroadcastChannels::event(1))->toBe('event.1')
        ->and(BroadcastChannels::event('{eventId}'))->toBe('event.{eventId}')
        ->and(BroadcastChannels::weather())->toBe(BroadcastChannels::sharedWeather());
});

test('authorizes only the visitor that owns the channel scope', function () {
    $authorize = BroadcastChannels::authorize(fn ($user, int $eventId): bool => $eventId === 1);
    $visitorAScope = str(BroadcastChannels::event(1))->between('demo.', '.event')->toString();

    expect($authorize(new stdClass, $visitorAScope, '1'))->toBeTrue()
        ->and($authorize(new stdClass, $visitorAScope, '2'))->toBeFalse();

    Config::set('database.connections.demo.database', VISITOR_B);

    expect($authorize(new stdClass, $visitorAScope, '1'))->toBeFalse();
});

test('passes authorization callbacks through when demo mode is disabled', function () {
    Config::set('demo.enabled', false);
    $callback = fn ($user, int $eventId): bool => true;

    expect(BroadcastChannels::authorize($callback))->toBe($callback);
});

test('broadcasts manual weather alerts to the visitor and the shared feed to everyone', function () {
    expect((new WeatherAlertChanged([], true, true))->broadcastOn()->name)->toBe(BroadcastChannels::weather())
        ->and((new WeatherAlertChanged([], true, false, shared: true))->broadcastOn()->name)->toBe('weather');
});

test('weather banner listens on both its own and the shared weather channel', function () {
    $listeners = Livewire::test(WeatherAlertBanner::class)->instance()->getListeners();

    expect($listeners)->toBe([
        BroadcastChannels::publicListener(BroadcastChannels::weather(), 'WeatherAlertChanged') => 'handleAlertUpdate',
        'echo:weather,WeatherAlertChanged' => 'handleSharedAlertUpdate',
    ]);
});
