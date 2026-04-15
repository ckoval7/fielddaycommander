<?php

use App\Models\User;
use App\Notifications\WeatherAlertMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;

uses(RefreshDatabase::class);

test('WeatherAlertMail sends via mail channel', function () {
    $user = User::factory()->make();
    $notification = new WeatherAlertMail(
        alerts: [['event' => 'Tornado Warning', 'headline' => 'Tornado Warning in effect']],
        title: 'Tornado Warning',
    );

    expect($notification->via($user))->toBe(['mail']);
});

test('WeatherAlertMail subject contains alert title', function () {
    $user = User::factory()->create(['first_name' => 'Jane']);
    $notification = new WeatherAlertMail(
        alerts: [['event' => 'Tornado Warning', 'headline' => 'Tornado Warning in effect until 8 PM']],
        title: 'Tornado Warning',
    );

    $mail = $notification->toMail($user);

    expect($mail)->toBeInstanceOf(MailMessage::class);
    expect($mail->subject)->toContain('Tornado Warning');
});

test('WeatherAlertMail body contains alert event and headline', function () {
    $user = User::factory()->create(['first_name' => 'Jane']);
    $notification = new WeatherAlertMail(
        alerts: [['event' => 'Tornado Warning', 'headline' => 'Tornado Warning in effect until 8 PM']],
        title: 'Tornado Warning',
    );

    $mail = $notification->toMail($user);
    $rendered = collect($mail->introLines)->join(' ');

    expect($rendered)->toContain('Tornado Warning')
        ->and($rendered)->toContain('Tornado Warning in effect until 8 PM');
});

test('WeatherAlertMail greeting includes user first name', function () {
    $user = User::factory()->create(['first_name' => 'Jane']);
    $notification = new WeatherAlertMail(
        alerts: [['event' => 'Tornado Warning', 'headline' => 'Test headline']],
        title: 'Tornado Warning',
    );

    $mail = $notification->toMail($user);

    expect($mail->greeting)->toContain('Jane');
});

test('WeatherAlertMail handles multiple alerts', function () {
    $user = User::factory()->create(['first_name' => 'Jane']);
    $notification = new WeatherAlertMail(
        alerts: [
            ['event' => 'Tornado Warning', 'headline' => 'First headline'],
            ['event' => 'Flash Flood Warning', 'headline' => 'Second headline'],
        ],
        title: '2 Active Weather Alerts',
    );

    $mail = $notification->toMail($user);
    $rendered = collect($mail->introLines)->join(' ');

    expect($rendered)->toContain('Tornado Warning')
        ->and($rendered)->toContain('Flash Flood Warning');
});
