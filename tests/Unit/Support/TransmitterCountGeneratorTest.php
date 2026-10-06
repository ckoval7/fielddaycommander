<?php

use App\Support\TransmitterCountGenerator;

test('class A counts stay within 1-20 and favor 4 and 5', function () {
    $counts = array_fill(1, 20, 0);

    foreach (range(1, 5000) as $i) {
        $count = TransmitterCountGenerator::forClass('A');
        expect($count)->toBeBetween(1, 20);
        $counts[$count]++;
    }

    arsort($counts);
    $mostCommon = array_slice(array_keys($counts), 0, 2);
    sort($mostCommon);

    $tenPlus = array_sum(array_slice($counts, 9, null, true));

    expect($mostCommon)->toBe([4, 5])
        ->and($counts[1])->toBeGreaterThan(300)
        ->and($tenPlus)->toBeLessThan(400)
        ->and($counts[10])->toBeGreaterThan($counts[15]);
});

test('other classes keep their transmitter count ranges', function () {
    foreach (range(1, 200) as $i) {
        expect(TransmitterCountGenerator::forClass('F'))->toBeBetween(2, 10)
            ->and(TransmitterCountGenerator::forClass('B'))->toBeBetween(1, 2)
            ->and(TransmitterCountGenerator::forClass('H'))->toBe(1);
    }
});
