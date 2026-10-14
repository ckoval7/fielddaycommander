<?php

use App\Events\ContactLogged;
use App\Events\ExternalContactDeleted;
use App\Events\ExternalContactReceived;
use App\Events\ExternalContactUpdated;
use App\Events\ExternalLoggerStatusChanged;
use App\Events\NewNotification;
use App\Models\Contact;
use App\Models\Event;
use App\Models\User;
use App\Support\BroadcastChannels;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;

/**
 * Check whether a channel name has an authorization callback registered in
 * routes/channels.php, using the same matching rules as Laravel's broadcaster.
 */
function hasRegisteredChannelPattern(string $channelName): bool
{
    $broadcaster = Broadcast::driver();
    $matches = new ReflectionMethod($broadcaster, 'channelNameMatchesPattern');

    return $broadcaster->getChannels()
        ->keys()
        ->contains(fn (string $pattern): bool => (bool) $matches->invoke($broadcaster, $channelName, $pattern));
}

test('every private channel an event broadcasts on has an authorization callback', function (object $event) {
    $privateChannels = collect($event->broadcastOn())
        ->filter(fn (Channel $channel): bool => $channel instanceof PrivateChannel);

    expect($privateChannels)->not->toBeEmpty();

    $privateChannels->each(function (PrivateChannel $channel): void {
        $name = str($channel->name)->after('private-')->toString();

        expect(hasRegisteredChannelPattern($name))->toBeTrue("No channel authorization registered for [{$name}]");
    });
})->with([
    'ContactLogged' => fn () => new ContactLogged(new Contact, (new Event)->forceFill(['id' => 7])),
    'ExternalContactReceived' => fn () => new ExternalContactReceived(new Contact, 12, 'n1mm', 7),
    'ExternalContactUpdated' => fn () => new ExternalContactUpdated(new Contact, 12, 'n1mm'),
    'ExternalContactDeleted' => fn () => new ExternalContactDeleted(1, 'W1AW', 12, 'n1mm'),
    'ExternalLoggerStatusChanged' => fn () => new ExternalLoggerStatusChanged('n1mm', 'running', 12),
    'NewNotification' => fn () => new NewNotification(3),
]);

test('channel authorization callbacks admit the expected users', function () {
    $channels = Broadcast::driver()->getChannels();
    $user = (new User)->forceFill(['id' => 3]);

    expect($channels[BroadcastChannels::event('{eventId}')]($user, '7'))->toBeTrue()
        ->and($channels[BroadcastChannels::externalLogger('{eventConfigId}')]($user, '12'))->toBeTrue()
        ->and($channels[BroadcastChannels::user('{id}')]($user, '3'))->toBeTrue()
        ->and($channels[BroadcastChannels::user('{id}')]($user, '4'))->toBeFalse();
});
