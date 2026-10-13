<?php

use App\Models\User;
use Illuminate\Support\Facades\Blade;

test('user menu links to my shifts', function () {
    $this->actingAs(User::factory()->create());

    $html = Blade::render('<x-user-menu />');

    expect($html)
        ->toContain('My Shifts')
        ->toContain(route('schedule.my-shifts'));
});
