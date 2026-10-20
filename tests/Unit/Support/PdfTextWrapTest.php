<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Support\PdfTextWrap;
use Dompdf\Dompdf;

beforeEach(function () {
    $this->wrap = new PdfTextWrap((new Dompdf)->getFontMetrics());
});

test('short or empty text takes one line', function (?string $text) {
    expect($this->wrap->lineCount($text, 136.0))->toBe(1);
})->with([
    'empty' => [''],
    'null' => [null],
    'one phone' => ['+1 (330) 555-1234'],
]);

test('a comma list of phones wraps like dompdf does in the owner contacts column', function () {
    // Measured on a rendered PDF: these four numbers take two lines in a 136pt cell.
    expect($this->wrap->lineCount('(480) 227-3882, 719-829-9328, +1.952.500.7645, 959-513-1297', 136.0))->toBe(2)
        ->and($this->wrap->lineCount('+1 (360) 281-2620, +1-602-379-8020, +1-949-722-7618, +15644651754', 136.0))->toBe(3);
});

test('a word wider than the box overflows on one line instead of counting extra lines', function () {
    expect($this->wrap->lineCount('averyveryverylongemailaddress@example.com', 40.0))->toBe(1);
});

test('line height follows the font height times the line-height ratio', function () {
    // dompdf rendered single-line owner contact rows 18.2pt tall: 6px padding,
    // 1px border (5.25pt) plus one line box.
    expect($this->wrap->lineHeight() + 5.25)->toEqualWithDelta(18.2, 0.1);
});
