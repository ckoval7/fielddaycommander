<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule event auto-activation
Schedule::command('events:activate-by-date')->everyFifteenMinutes();

// Close operating sessions for events that have ended
Schedule::command('sessions:close-expired')->everyFifteenMinutes();

// Close idle external logger sessions
Schedule::command('external-logger:close-idle')->everyFiveMinutes();

// Send all scheduled reminders (bulletins, shift check-ins, etc.)
Schedule::command('reminders:send')->everyMinute();

// Clean up expired album exports
Schedule::command('exports:clean')->daily();

// Monitor and restart crashed external logger listeners
Schedule::command('external-logger:monitor')->everyMinute();
