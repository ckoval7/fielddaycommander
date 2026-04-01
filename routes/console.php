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

// Send W1AW bulletin transmission reminders
Schedule::command('bulletins:send-reminders')->everyMinute();
