<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Services\N3fjp\N3fjpFrameDecoder;
use App\Services\N3fjp\N3fjpSendQueue;

/**
 * A full log of $records GET messages, each about 1 KB on the wire.
 *
 * @return list<string>
 */
function n3fjpBigLog(int $records): array
{
    $messages = ['<BOR><NTWK><TRANSACTION>CLEAR</TRANSACTION><EOR>'];

    foreach (range(1, $records) as $i) {
        $messages[] = "<BOR><NTWK><TRANSACTION>GET</TRANSACTION><XMLDATA><fldPrimaryKey>{$i}</fldPrimaryKey><fldCall>K{$i}FDC</fldCall><fldComments>".str_repeat('x', 400).'</fldComments></XMLDATA><EOR>';
    }

    $messages[] = '<BOR><NTWK><TRANSACTION>LIST</TRANSACTION><EOR>';

    return $messages;
}

/**
 * Read everything from the queue the way a socket would, $chunk bytes at a time.
 */
function n3fjpDrain(N3fjpSendQueue $queue, int $chunk = 65_536): string
{
    $received = '';

    while (! $queue->isEmpty()) {
        $bytes = substr($queue->nextBytes(), 0, $chunk);
        $queue->sent(strlen($bytes));
        $received .= $bytes;
    }

    return $received;
}

test('a full log over the limit is not counted against a client that reads it', function () {
    $queue = new N3fjpSendQueue(16_777_216);
    $log = n3fjpBigLog(20_000);

    $queue->pushFullLog($log);

    expect($queue->isOverLimit())->toBeFalse()
        ->and(strlen($queue->nextBytes()))->toBeLessThan(N3fjpSendQueue::READY_BYTES + 4096)
        ->and(n3fjpDrain($queue))->toBe(implode('', array_map(N3fjpFrameDecoder::encode(...), $log)));
});

test('messages queued during a full log arrive after it, in order', function () {
    $queue = new N3fjpSendQueue(16_777_216);

    $queue->push('<BOR><WHO><EOR>');
    $queue->pushFullLog(n3fjpBigLog(1_000));
    $queue->nextBytes();
    $queue->push('<BOR><NTWK><TRANSACTION>ADD</TRANSACTION><EOR>');

    $received = n3fjpDrain($queue);
    $messages = (new N3fjpFrameDecoder)->feed($received);

    expect($messages)->toHaveCount(1_004)
        ->and($messages[0])->toBe('<BOR><WHO><EOR>')
        ->and($messages[1])->toContain('CLEAR')
        ->and($messages[1_002])->toContain('LIST')
        ->and($messages[1_003])->toContain('ADD');
});

test('a client that stops reading during a full log is over the limit once other traffic piles up', function () {
    $queue = new N3fjpSendQueue(1_000_000);
    $queue->pushFullLog(n3fjpBigLog(5_000));
    $chat = '<BOR><MESG><MSGTXT>'.str_repeat('y', 1_000).'</MSGTXT><EOR>';

    foreach (range(1, 400) as $ignored) {
        $queue->push($chat);
    }

    expect($queue->isOverLimit())->toBeFalse();

    foreach (range(1, 100) as $ignored) {
        $queue->push($chat);
    }

    expect($queue->isOverLimit())->toBeTrue();
});

test('a second full log queued behind the first counts against the limit until the first is sent', function () {
    $queue = new N3fjpSendQueue(1_000_000);
    $first = n3fjpBigLog(5_000);

    $queue->pushFullLog($first);

    expect($queue->isOverLimit())->toBeFalse();

    $queue->pushFullLog(n3fjpBigLog(5_000));

    expect($queue->isOverLimit())->toBeTrue();

    $firstBytes = strlen(implode('', array_map(N3fjpFrameDecoder::encode(...), $first)));

    while ($firstBytes > 0) {
        $sent = min($firstBytes, strlen($queue->nextBytes()));
        $queue->sent($sent);
        $firstBytes -= $sent;
    }

    expect($queue->isOverLimit())->toBeFalse();
});
