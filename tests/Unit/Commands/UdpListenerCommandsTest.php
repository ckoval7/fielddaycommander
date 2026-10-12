<?php

use App\Console\Commands\UdpAdifListenCommand;
use App\Console\Commands\WsjtxListenCommand;
use App\DTOs\ExternalContactDto;
use App\Exceptions\OutOfPeriodContactException;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\ExternalLoggerSetting;
use App\Services\AdifContactMapper;
use App\Services\AdifParserService;
use App\Services\ExternalContactHandler;
use App\Services\WsjtxPacketParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * These tests exercise the listener commands' packet handling and startup
 * guards directly, without ever opening or binding a UDP socket.
 */

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    $this->handler = Mockery::mock(ExternalContactHandler::class);
    $this->lastLogKey = 'external-logger:test:last-log';

    $this->adifRecord = '<call:4>W1AW <qso_date:8>20260628 <time_on:6>184300 '
        .'<band:3>20m <mode:4>MFSK <submode:3>FT8 <arrl_sect:2>CT <eor>';

    $this->wsjtxPacket = function (int $messageType, string $payload = ''): string {
        $utf8 = fn (string $value): string => pack('N', strlen($value)).$value;

        return pack('N', 0xADBCCBDA).pack('N', 3).pack('N', $messageType).$utf8('WSJT-X').$payload;
    };

    $this->wsjtxLoggedAdifPacket = fn (string $adif): string => ($this->wsjtxPacket)(12, pack('N', strlen($adif)).$adif);

    /**
     * Build a listener with its collaborators injected and return a closure that
     * invokes its protected processPacket() method.
     */
    $this->packetProcessor = function (string $commandClass): Closure {
        $command = new $commandClass;
        $handler = $this->handler;

        Closure::bind(function () use ($handler) {
            $this->adifParser = new AdifParserService;
            $this->mapper = new AdifContactMapper;
            $this->handler = $handler;

            if ($this instanceof WsjtxListenCommand) {
                $this->wsjtxParser = new WsjtxPacketParser;
            }
        }, $command, $commandClass)();

        return Closure::bind(
            fn (string $buffer, EventConfiguration $config, string $lastLogKey): int => $this->processPacket($buffer, '127.0.0.1', $config, $lastLogKey),
            $command,
            $commandClass,
        );
    };

    $this->config = EventConfiguration::factory()->create(['is_active' => true]);
});

describe('startup guards', function () {
    test('fails without binding when there is no active event configuration', function (string $command) {
        $this->config->update(['is_active' => false]);

        $this->artisan($command)
            ->expectsOutput('No active event configuration found.')
            ->assertExitCode(1);
    })->with(['external-logger:wsjtx', 'external-logger:udp-adif']);

    test('fails without binding when the listener is not enabled', function (string $command, string $type, string $label) {
        ExternalLoggerSetting::create([
            'event_configuration_id' => $this->config->id,
            'listener_type' => $type,
            'is_enabled' => false,
            'port' => 2237,
        ]);

        $this->artisan($command)
            ->expectsOutput("{$label} listener is not enabled for this event.")
            ->assertExitCode(1);

        expect(ExternalLoggerSetting::where('listener_type', $type)->value('pid'))->toBeNull();
    })->with([
        ['external-logger:wsjtx', 'wsjtx', 'WSJTX'],
        ['external-logger:udp-adif', 'udp-adif', 'UDP ADIF'],
    ]);

    test('uses the event option instead of the active event', function () {
        $otherConfig = EventConfiguration::factory()->create(['is_active' => false]);
        ExternalLoggerSetting::create([
            'event_configuration_id' => $this->config->id,
            'listener_type' => 'udp-adif',
            'is_enabled' => true,
            'port' => 2238,
        ]);

        // The active event is enabled, but the explicitly requested one is not.
        $this->artisan('external-logger:udp-adif', ['--event' => $otherConfig->id])
            ->expectsOutput('UDP ADIF listener is not enabled for this event.')
            ->assertExitCode(1);
    });
});

describe('UDP ADIF packet processing', function () {
    test('skips WSJTX binary packets', function () {
        $this->handler->shouldNotReceive('handleContact');
        $process = ($this->packetProcessor)(UdpAdifListenCommand::class);

        $processed = $process(($this->wsjtxLoggedAdifPacket)($this->adifRecord), $this->config, $this->lastLogKey);

        expect($processed)->toBe(0)
            ->and(Cache::has($this->lastLogKey))->toBeFalse();
    });

    test('ignores packets without ADIF records', function () {
        $this->handler->shouldNotReceive('handleContact');
        $process = ($this->packetProcessor)(UdpAdifListenCommand::class);

        expect($process('hello world', $this->config, $this->lastLogKey))->toBe(0);
    });

    test('hands each mappable record to the contact handler and records the last log', function () {
        $this->handler->shouldReceive('handleContact')
            ->twice()
            ->withArgs(fn (ExternalContactDto $dto, EventConfiguration $config) => $dto->source === 'udp-adif' && $config->is($this->config))
            ->andReturn(new Contact);
        $process = ($this->packetProcessor)(UdpAdifListenCommand::class);

        $packet = $this->adifRecord
            .'<call:5>K1ABC <qso_date:8>20260628 <time_on:6>184500 <band:3>40m <mode:2>CW <eor>'
            .'<call:4>N0NE <band:3>20m <eor>'; // missing date/time, not mappable

        expect($process($packet, $this->config, $this->lastLogKey))->toBe(2);

        $lastLog = Cache::get($this->lastLogKey);

        expect($lastLog['callsign'])->toBe('K1ABC')
            ->and($lastLog['source'])->toBe('udp-adif')
            ->and($lastLog['accepted'])->toBeTrue()
            ->and($lastLog['rejection_reason'])->toBeNull();
    });

    test('records out-of-period contacts as rejected but still counts them', function () {
        $this->handler->shouldReceive('handleContact')->once()->andThrow(new OutOfPeriodContactException);
        $process = ($this->packetProcessor)(UdpAdifListenCommand::class);

        expect($process($this->adifRecord, $this->config, $this->lastLogKey))->toBe(1);

        expect(Cache::get($this->lastLogKey))
            ->toMatchArray([
                'callsign' => 'W1AW',
                'band' => '20m',
                'mode' => 'FT8',
                'section' => 'CT',
                'accepted' => false,
                'rejection_reason' => 'outside event window',
            ]);
    });
});

describe('WSJTX packet processing', function () {
    test('handles a logged ADIF packet', function () {
        $this->handler->shouldReceive('handleContact')
            ->once()
            ->withArgs(fn (ExternalContactDto $dto) => $dto->callsign === 'W1AW' && $dto->source === 'wsjtx')
            ->andReturn(new Contact);
        $process = ($this->packetProcessor)(WsjtxListenCommand::class);

        expect($process(($this->wsjtxLoggedAdifPacket)($this->adifRecord), $this->config, $this->lastLogKey))->toBe(1)
            ->and(Cache::get($this->lastLogKey))->toMatchArray(['callsign' => 'W1AW', 'source' => 'wsjtx', 'accepted' => true]);
    });

    test('ignores heartbeat and non-WSJTX packets', function () {
        $this->handler->shouldNotReceive('handleContact');
        $process = ($this->packetProcessor)(WsjtxListenCommand::class);

        expect($process(($this->wsjtxPacket)(0), $this->config, $this->lastLogKey))->toBe(0)
            ->and($process($this->adifRecord, $this->config, $this->lastLogKey))->toBe(0)
            ->and(Cache::has($this->lastLogKey))->toBeFalse();
    });

    test('ignores logged ADIF packets without a mappable record', function () {
        $this->handler->shouldNotReceive('handleContact');
        $process = ($this->packetProcessor)(WsjtxListenCommand::class);

        expect($process(($this->wsjtxLoggedAdifPacket)('<call:4>W1AW <eor>'), $this->config, $this->lastLogKey))->toBe(0);
    });

    test('records out-of-period contacts as rejected', function () {
        $this->handler->shouldReceive('handleContact')->once()->andThrow(new OutOfPeriodContactException);
        $process = ($this->packetProcessor)(WsjtxListenCommand::class);

        expect($process(($this->wsjtxLoggedAdifPacket)($this->adifRecord), $this->config, $this->lastLogKey))->toBe(1)
            ->and(Cache::get($this->lastLogKey))->toMatchArray(['accepted' => false, 'rejection_reason' => 'outside event window']);
    });
});
