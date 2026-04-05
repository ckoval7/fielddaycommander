<?php

use App\Support\CallsignGenerator;

test('us() generates callsigns matching FCC format', function () {
    foreach (range(1, 200) as $i) {
        $callsign = CallsignGenerator::us();
        expect($callsign)->toMatch(
            '/^(W|K|N|AA|AB|AC|AD|AE|AF|AG|AH|AI|AJ|AK|AL|[KNW][A-Z])\d[A-Z]{1,3}$/'
        );
    }
});

test('canada() generates callsigns matching Industry Canada format', function () {
    foreach (range(1, 200) as $i) {
        $callsign = CallsignGenerator::canada();
        expect($callsign)->toMatch('/^(VE[123456789]|VA[234567]|VY[12])[A-Z]{2,3}$/');
    }
});

test('any() returns a mix weighted toward US callsigns', function () {
    $usCount = 0;
    foreach (range(1, 500) as $i) {
        if (! str_starts_with(CallsignGenerator::any(), 'V')) {
            $usCount++;
        }
    }
    expect($usCount / 500)->toBeGreaterThan(0.65);
});

test('generated callsigns contain no lowercase letters or spaces', function () {
    foreach (range(1, 100) as $i) {
        $callsign = CallsignGenerator::any();
        expect($callsign)->toMatch('/^[A-Z0-9]+$/');
    }
});
