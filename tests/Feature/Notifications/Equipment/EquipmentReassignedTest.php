<?php

use App\Models\Equipment;
use App\Models\Station;
use App\Models\User;
use App\Notifications\Equipment\EquipmentReassigned;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->notifiable = User::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'call_sign' => 'K3UHF',
        'email' => 'manager@example.com',
    ]);

    $this->reassignedBy = User::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'call_sign' => 'W1AW',
    ]);

    $this->equipment = Equipment::factory()->create([
        'make' => 'Icom',
        'model' => 'IC-7300',
        'type' => 'radio',
    ]);

    $this->oldStation = Station::factory()->create(['name' => 'Alpha Station']);
    $this->newStation = Station::factory()->create(['name' => 'Bravo Station']);
});

test('EquipmentReassigned uses mail channel', function () {
    $notification = new EquipmentReassigned(
        $this->equipment,
        $this->oldStation,
        $this->newStation,
        $this->reassignedBy,
    );

    expect($notification->via($this->notifiable))->toBe(['mail']);
});

test('EquipmentReassigned implements ShouldQueue', function () {
    $notification = new EquipmentReassigned(
        $this->equipment,
        $this->oldStation,
        $this->newStation,
        $this->reassignedBy,
    );

    expect($notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('EquipmentReassigned mail subject contains equipment name', function () {
    Notification::fake();

    $notification = new EquipmentReassigned(
        $this->equipment,
        $this->oldStation,
        $this->newStation,
        $this->reassignedBy,
    );

    $this->notifiable->notify($notification);

    Notification::assertSentTo(
        $this->notifiable,
        EquipmentReassigned::class,
        function ($notification, $channels) {
            expect($channels)->toBe(['mail']);

            $mail = $notification->toMail($this->notifiable);
            expect($mail->subject)->toContain('Equipment Reassigned')
                ->and($mail->subject)->toContain('Icom IC-7300');

            return true;
        }
    );
});

test('EquipmentReassigned mail contains station reassignment details', function () {
    Notification::fake();

    $notification = new EquipmentReassigned(
        $this->equipment,
        $this->oldStation,
        $this->newStation,
        $this->reassignedBy,
    );

    $this->notifiable->notify($notification);

    Notification::assertSentTo(
        $this->notifiable,
        EquipmentReassigned::class,
        function ($notification) {
            $mail = $notification->toMail($this->notifiable);
            $introLines = collect($mail->introLines);

            expect($introLines->implode(' '))->toContain('Alpha Station')
                ->and($introLines->implode(' '))->toContain('Bravo Station')
                ->and($introLines->implode(' '))->toContain('John Doe')
                ->and($introLines->implode(' '))->toContain('W1AW');

            return true;
        }
    );
});

test('EquipmentReassigned mail does not include reason when not provided', function () {
    $notification = new EquipmentReassigned(
        $this->equipment,
        $this->oldStation,
        $this->newStation,
        $this->reassignedBy,
    );

    $mail = $notification->toMail($this->notifiable);
    $introLines = collect($mail->introLines);

    expect($introLines->implode(' '))->not->toContain('Reason');
});

test('EquipmentReassigned mail includes reason when provided', function () {
    $notification = new EquipmentReassigned(
        $this->equipment,
        $this->oldStation,
        $this->newStation,
        $this->reassignedBy,
        'Equipment needed for higher priority station',
    );

    $mail = $notification->toMail($this->notifiable);
    $introLines = collect($mail->introLines);

    expect($introLines->implode(' '))->toContain('Reason')
        ->and($introLines->implode(' '))->toContain('Equipment needed for higher priority station');
});

test('EquipmentReassigned mail uses equipment type when make/model are empty', function () {
    $equipment = Equipment::factory()->create([
        'make' => '',
        'model' => '',
        'type' => 'antenna',
    ]);

    $notification = new EquipmentReassigned(
        $equipment,
        $this->oldStation,
        $this->newStation,
        $this->reassignedBy,
    );

    $mail = $notification->toMail($this->notifiable);

    expect($mail->subject)->toContain('Antenna');
});

test('EquipmentReassigned toArray returns correct structure', function () {
    $notification = new EquipmentReassigned(
        $this->equipment,
        $this->oldStation,
        $this->newStation,
        $this->reassignedBy,
        'Operational priority',
    );

    $array = $notification->toArray($this->notifiable);

    expect($array)
        ->toHaveKey('equipment_id', $this->equipment->id)
        ->toHaveKey('old_station_id', $this->oldStation->id)
        ->toHaveKey('old_station_name', 'Alpha Station')
        ->toHaveKey('new_station_id', $this->newStation->id)
        ->toHaveKey('new_station_name', 'Bravo Station')
        ->toHaveKey('reassigned_by_user_id', $this->reassignedBy->id)
        ->toHaveKey('reason', 'Operational priority')
        ->toHaveKey('reassigned_at');
});

test('EquipmentReassigned toArray reason is null when not provided', function () {
    $notification = new EquipmentReassigned(
        $this->equipment,
        $this->oldStation,
        $this->newStation,
        $this->reassignedBy,
    );

    $array = $notification->toArray($this->notifiable);

    expect($array['reason'])->toBeNull();
});

test('EquipmentReassigned mail includes value when equipment has value_usd', function () {
    $equipment = Equipment::factory()->create([
        'make' => 'Yaesu',
        'model' => 'FT-991A',
        'type' => 'radio',
        'value_usd' => 900,
    ]);

    $notification = new EquipmentReassigned(
        $equipment,
        $this->oldStation,
        $this->newStation,
        $this->reassignedBy,
    );

    $mail = $notification->toMail($this->notifiable);
    $introLines = collect($mail->introLines);

    expect($introLines->implode(' '))->toContain('900');
});
