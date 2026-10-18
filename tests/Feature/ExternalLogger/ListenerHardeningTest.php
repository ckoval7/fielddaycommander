<?php

use App\Console\Commands\N1mmListenCommand;
use App\DTOs\ExternalContactDto;
use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\ExternalLoggerSetting;
use App\Models\Mode;
use App\Models\Section;
use App\Models\Station;
use App\Services\ExternalContactHandler;
use App\Services\ExternalLoggerManager;
use App\Services\N1mmPacketParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    $this->config = EventConfiguration::factory()->create(['is_active' => true]);
    $this->inWindow = $this->config->event->start_time->copy()->addHour();

    Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'CONTEST-PC']);
    Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    Mode::firstOrCreate(['name' => 'CW'], ['category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
    Mode::firstOrCreate(['name' => 'Digital'], ['category' => 'Digital', 'points_fd' => 2, 'points_wfd' => 2]);
    Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);
});

/**
 * Run the N1MM command's packet handling directly, without a socket.
 */
function processN1mmPacket(N1mmListenCommand $command, string $packet, EventConfiguration $config): int
{
    return (function () use ($packet, $config) {
        $this->parser ??= app(N1mmPacketParser::class);
        $this->handler ??= app(ExternalContactHandler::class);

        return $this->processPacket($packet, '192.0.2.10', $config, 'last-log');
    })->call($command);
}

function n1mmContactXml(string $call, Carbon $timestamp, string $id = 'abc123'): string
{
    return '<?xml version="1.0" encoding="utf-8"?><contactinfo><timestamp>'.$timestamp->format('Y-m-d H:i:s')
        .'</timestamp><band>14</band><rxfreq>1402500</rxfreq><operator>K3CPK</operator><mode>CW</mode>'
        ."<call>{$call}</call><exchange1>2B</exchange1><section>CT</section><StationName>CONTEST-PC</StationName>"
        ."<ID>{$id}</ID></contactinfo>";
}

test('the Docker image installs the sockets extension the UDP listeners need', function () {
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect(preg_match('/install-php-extensions((?:\s*\\\\\s*\n\s*[a-z_]+)+)/', $dockerfile, $matches))->toBe(1);

    $extensions = preg_split('/[\s\\\\]+/', trim($matches[1]), flags: PREG_SPLIT_NO_EMPTY);

    expect($extensions)->toContain('sockets');
});

test('a listener without the sockets extension fails cleanly, clears its pid and records why', function () {
    Log::spy();

    $setting = ExternalLoggerSetting::create([
        'event_configuration_id' => $this->config->id,
        'listener_type' => 'n1mm',
        'is_enabled' => true,
        'port' => 12060,
    ]);

    $this->app->bind(N1mmListenCommand::class, fn () => new class extends N1mmListenCommand
    {
        protected function socketsAvailable(): bool
        {
            return false;
        }
    });

    $this->artisan('external-logger:n1mm')
        ->expectsOutputToContain('sockets extension is not installed')
        ->assertExitCode(1);

    $manager = app(ExternalLoggerManager::class);

    expect($setting->refresh()->pid)->toBeNull()
        ->and($manager->getStartupFailure($this->config->id, 'n1mm')['reason'])->toContain('sockets extension')
        ->and($manager->isBackingOff($this->config->id, 'n1mm'))->toBeTrue();

    Log::shouldHaveReceived('error')->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'sockets extension'));
});

test('malformed N1MM packets are logged, throttled so a noisy sender cannot flood the log', function () {
    Log::spy();
    $command = app(N1mmListenCommand::class);

    foreach (range(1, 25) as $i) {
        processN1mmPacket($command, "<contactinfo><call>W1AW</call>{$i}", $this->config);
    }

    Log::shouldHaveReceived('warning')->times(10)
        ->withArgs(fn (string $message): bool => str_contains($message, 'not well-formed XML'));
});

test('well-formed N1MM packets the listener ignores on purpose are not logged as malformed', function () {
    Log::spy();
    $command = app(N1mmListenCommand::class);

    processN1mmPacket($command, '<?xml version="1.0"?><spectrum><app>N1MM</app></spectrum>', $this->config);

    Log::shouldNotHaveReceived('warning');
});

test('an out-of-window N1MM contact is rejected with a warning naming its call and time', function () {
    Event::fake();
    Log::spy();
    $command = app(N1mmListenCommand::class);
    $tooEarly = $this->config->event->start_time->copy()->subDay();

    processN1mmPacket($command, n1mmContactXml('W1AW', $tooEarly), $this->config);

    expect(Contact::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'outside the event window')
            && $context['callsign'] === 'W1AW'
            && $context['qso_time'] === $tooEarly->toIso8601String());
});

test('a contact with an over-long external id is stored, and later packets for it still find it', function () {
    Event::fake();
    Log::spy();
    $handler = app(ExternalContactHandler::class);
    $longId = str_repeat('a1b2c3d4', 16);

    $dto = fn (string $call) => new ExternalContactDto(
        callsign: $call,
        timestamp: $this->inWindow,
        source: 'n1mm',
        bandName: '20m',
        modeName: 'CW',
        stationIdentifier: 'CONTEST-PC',
        externalId: $longId,
    );

    $contact = $handler->handleContact($dto('W1AW'), $this->config);
    $handler->handleContact($dto('W1AW'), $this->config);

    expect(Contact::count())->toBe(1)
        ->and(strlen($contact->fresh()->external_id))->toBeLessThanOrEqual(100)
        ->and($contact->fresh()->external_id)->toBe('sha256:'.hash('sha256', $longId));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'over-long QSO id'));
});

test('an over-long mode name is cut to the submode column and logged', function () {
    Event::fake();
    Log::spy();

    $contact = app(ExternalContactHandler::class)->handleContact(new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: $this->inWindow,
        source: 'udp-adif',
        bandName: '20m',
        modeName: 'SOME-VERY-LONG-DIGITAL-MODE-NAME',
        stationIdentifier: 'CONTEST-PC',
    ), $this->config);

    expect($contact->fresh()->submode)->toBe('SOME-VERY-LONG-DIGIT');
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'over-long mode name'));
});
