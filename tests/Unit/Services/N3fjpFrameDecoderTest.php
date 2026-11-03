<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Services\N3fjp\N3fjpFrameDecoder;
use App\Services\N3fjp\N3fjpProtocolException;

test('encodes messages as UTF-16LE ending in ETX EOT BEL', function () {
    expect(N3fjpFrameDecoder::encode('<WHO>'))->toBe("<\0W\0H\0O\0>\0\x03\0\x04\0\x07\0");
});

test('decodes several messages arriving together', function () {
    $decoder = new N3fjpFrameDecoder;

    $messages = $decoder->feed(N3fjpFrameDecoder::encode('<BOR><WHO><EOR>').N3fjpFrameDecoder::encode('<BOR><NTWK><OPEN>'));

    expect($messages)->toBe(['<BOR><WHO><EOR>', '<BOR><NTWK><OPEN>']);
});

test('keeps a message split mid-character and mid-terminator until it completes', function () {
    $decoder = new N3fjpFrameDecoder;
    $bytes = N3fjpFrameDecoder::encode('<BOR><MESG><MSGTXT>73 de Mötley</MSGTXT><EOR>');
    $collected = [];

    foreach (str_split($bytes, 3) as $chunk) {
        $collected = [...$collected, ...$decoder->feed($chunk)];
    }

    expect($collected)->toBe(['<BOR><MESG><MSGTXT>73 de Mötley</MSGTXT><EOR>']);
});

test('ignores terminator bytes that straddle a character boundary', function () {
    $decoder = new N3fjpFrameDecoder;

    // These encode as 41 03 00 04 00 07 00 41: the terminator bytes, one byte off.
    $text = "\u{0341}\u{0400}\u{0700}\u{4100}";

    expect($decoder->feed(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')))->toBe([])
        ->and($decoder->feed(N3fjpFrameDecoder::encode('')))->toBe([$text]);
});

test('rejects a peer that never sends a terminator', function () {
    $decoder = new N3fjpFrameDecoder;

    $decoder->feed(str_repeat('A', N3fjpFrameDecoder::MAX_BUFFER_BYTES + 2));
})->throws(N3fjpProtocolException::class);
