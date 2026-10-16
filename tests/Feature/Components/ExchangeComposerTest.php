<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;

beforeEach(function () {
    view()->share('errors', new ViewErrorBag);
});

it('keeps the highlighted suggestion on the primary fill without a competing hover background', function () {
    $html = Blade::render('<x-logging.exchange-composer :suggestions="$suggestions" />', [
        'suggestions' => [['exchange' => 'W1AW 3A CT', 'worked_on' => '40m Phone']],
    ]);

    expect($html)
        ->toContain('W1AW 3A CT')
        ->toContain("'bg-primary text-primary-content': si === 0")
        ->not->toContain('hover:bg-base-200')
        ->not->toContain('text-primary-content/60');
});
