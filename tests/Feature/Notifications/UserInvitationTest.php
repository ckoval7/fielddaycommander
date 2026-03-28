<?php

use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->invitedUser = User::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'call_sign' => 'KD9ABC',
        'email' => 'jane@example.com',
    ]);

    $this->token = 'test-invitation-token-abc123';
    $this->adminName = 'John Admin';
});

test('UserInvitation uses mail channel only', function () {
    $notification = new UserInvitation($this->token, $this->adminName);

    expect($notification->via($this->invitedUser))->toBe(['mail']);
});

test('UserInvitation mail has correct subject', function () {
    $notification = new UserInvitation($this->token, $this->adminName);

    $mail = $notification->toMail($this->invitedUser);

    expect($mail->subject)->toContain("You've been invited to FD Log DB");
});

test('UserInvitation mail greeting contains user first name', function () {
    $notification = new UserInvitation($this->token, $this->adminName);

    $mail = $notification->toMail($this->invitedUser);

    expect($mail->greeting)->toContain('Jane');
});

test('UserInvitation mail body contains admin name', function () {
    $notification = new UserInvitation($this->token, $this->adminName);

    $mail = $notification->toMail($this->invitedUser);

    $introLines = implode(' ', $mail->introLines);

    expect($introLines)->toContain('John Admin');
});

test('UserInvitation mail body contains user call sign', function () {
    $notification = new UserInvitation($this->token, $this->adminName);

    $mail = $notification->toMail($this->invitedUser);

    $introLines = implode(' ', $mail->introLines);

    expect($introLines)->toContain('KD9ABC');
});

test('UserInvitation action URL contains the invitation token', function () {
    $notification = new UserInvitation($this->token, $this->adminName);

    $mail = $notification->toMail($this->invitedUser);

    expect($mail->actionUrl)->toContain($this->token);
});

test('UserInvitation action URL points to register invite route', function () {
    $notification = new UserInvitation($this->token, $this->adminName);

    $mail = $notification->toMail($this->invitedUser);

    expect($mail->actionUrl)->toContain('/register/invite/');
});

test('UserInvitation mail mentions 72 hour expiry', function () {
    $notification = new UserInvitation($this->token, $this->adminName);

    $mail = $notification->toMail($this->invitedUser);

    $outroLines = implode(' ', $mail->outroLines);

    expect($outroLines)->toContain('72 hours');
});

test('UserInvitation toArray returns token and admin name', function () {
    $notification = new UserInvitation($this->token, $this->adminName);

    $array = $notification->toArray($this->invitedUser);

    expect($array)->toBe([
        'token' => 'test-invitation-token-abc123',
        'admin_name' => 'John Admin',
    ]);
});

test('UserInvitation is sent to user via Notification facade', function () {
    Notification::fake();

    $this->invitedUser->notify(new UserInvitation($this->token, $this->adminName));

    Notification::assertSentTo(
        $this->invitedUser,
        UserInvitation::class,
        function ($notification, $channels) {
            expect($channels)->toBe(['mail']);

            $mail = $notification->toMail($this->invitedUser);
            expect($mail->subject)->toContain("You've been invited to FD Log DB")
                ->and($mail->actionUrl)->toContain($this->token);

            return true;
        }
    );
});
