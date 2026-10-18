<?php

use App\Support\CsvSafe;

test('prefixes cells that would start a spreadsheet formula', function (string $value) {
    expect(CsvSafe::cell($value))->toBe("'".$value);
})->with([
    'equals' => ['=HYPERLINK("http://evil","x")'],
    'plus' => ['+cmd|" /C calc"!A0'],
    'minus with a function' => ['-SUM(A1:A2)'],
    'plus with a cell reference' => ['+A1'],
    'minus with a link' => ['-1+HYPERLINK("http://evil")'],
    'phone digits after a tab' => ["\t+1 555-1234"],
    'phone digits with equals' => ['+1=1'],
    'at' => ['@SUM(A1:A2)'],
    'tab' => ["\t=1+1"],
    'carriage return' => ["\r=1+1"],
    'leading spaces' => ['  =1+1'],
]);

test('leaves ordinary values untouched', function (mixed $value) {
    expect(CsvSafe::cell($value))->toBe($value);
})->with([
    'callsign' => ['W1AW/VE'],
    'empty' => [''],
    'negative number string' => ['-20'],
    'decimal string' => ['14.250'],
    'phone with country code' => ['+1 555-1234'],
    'formatted phone' => ['+1 (555) 123-4567'],
    'phone with dots' => ['555.123.4567'],
    'negative five' => ['-5'],
    'arithmetic only' => ['-2+3'],
    'integer' => [-5],
    'float' => [1.5],
    'null' => [null],
]);

test('row sanitizes every cell', function () {
    expect(CsvSafe::row(['W1AW', '=1+1', 3]))->toBe(['W1AW', "'=1+1", 3]);
});
