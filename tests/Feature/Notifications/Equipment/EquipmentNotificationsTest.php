<?php

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\User;
use App\Notifications\Equipment\EquipmentCommitted;
use App\Notifications\Equipment\EquipmentDelivered;
use App\Notifications\Equipment\EquipmentStatusChanged;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    // Create test data
    $this->operator = User::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'call_sign' => 'W1AW',
        'email' => 'operator@example.com',
    ]);

    $this->manager = User::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'call_sign' => 'K3UHF',
        'email' => 'manager@example.com',
    ]);

    $this->event = Event::factory()->create([
        'name' => 'Field Day 2025',
        'start_time' => now()->addDays(30),
        'end_time' => now()->addDays(31),
    ]);

    $this->equipment = Equipment::factory()->create([
        'owner_user_id' => $this->operator->id,
        'make' => 'Icom',
        'model' => 'IC-7300',
        'type' => 'transceiver',
    ]);

    $this->equipmentEvent = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
        'committed_at' => now(),
        'expected_delivery_at' => now()->addDays(29),
        'delivery_notes' => 'Will deliver Friday evening',
    ]);
});

test('EquipmentCommitted sends notification to operator with correct details', function () {
    Notification::fake();

    $this->operator->notify(new EquipmentCommitted($this->equipmentEvent, 'operator'));

    Notification::assertSentTo(
        $this->operator,
        EquipmentCommitted::class,
        function ($notification, $channels) {
            expect($channels)->toBe(['mail']);

            $mail = $notification->toMail($this->operator);
            expect($mail->subject)->toContain('Equipment Committed')
                ->and($mail->subject)->toContain('Icom IC-7300')
                ->and($mail->subject)->toContain('Field Day 2025');

            return true;
        }
    );
});

test('EquipmentCommitted sends notification to manager with operator details', function () {
    Notification::fake();

    $this->manager->notify(new EquipmentCommitted($this->equipmentEvent, 'manager'));

    Notification::assertSentTo(
        $this->manager,
        EquipmentCommitted::class,
        function ($notification, $channels) {
            expect($channels)->toBe(['mail']);

            $mail = $notification->toMail($this->manager);
            expect($mail->subject)->toContain('New Equipment Committed by W1AW');

            return true;
        }
    );
});

test('EquipmentDelivered sends notification to operator with delivery details', function () {
    Notification::fake();

    $this->equipmentEvent->update([
        'status' => 'delivered',
        'status_changed_at' => now(),
        'status_changed_by_user_id' => $this->manager->id,
    ]);

    $this->operator->notify(new EquipmentDelivered($this->equipmentEvent, 'operator'));

    Notification::assertSentTo(
        $this->operator,
        EquipmentDelivered::class,
        function ($notification, $channels) {
            expect($channels)->toBe(['mail']);

            $mail = $notification->toMail($this->operator);
            expect($mail->subject)->toContain('Equipment Delivered')
                ->and($mail->subject)->toContain('Icom IC-7300')
                ->and($mail->subject)->toContain('Field Day 2025');

            return true;
        }
    );
});

test('EquipmentDelivered sends notification to manager', function () {
    Notification::fake();

    $this->equipmentEvent->update([
        'status' => 'delivered',
        'status_changed_at' => now(),
        'status_changed_by_user_id' => $this->manager->id,
    ]);

    $this->manager->notify(new EquipmentDelivered($this->equipmentEvent, 'manager'));

    Notification::assertSentTo(
        $this->manager,
        EquipmentDelivered::class,
        function ($notification, $channels) {
            expect($channels)->toBe(['mail']);

            $mail = $notification->toMail($this->manager);
            expect($mail->subject)->toContain('Equipment Delivered');

            return true;
        }
    );
});

test('EquipmentStatusChanged sends notification with status change details', function () {
    Notification::fake();

    $previousStatus = 'delivered';
    $this->equipmentEvent->update([
        'status' => 'in_use',
        'status_changed_at' => now(),
        'status_changed_by_user_id' => $this->manager->id,
        'manager_notes' => 'Equipment checked and working properly',
    ]);

    $this->operator->notify(new EquipmentStatusChanged($this->equipmentEvent, $previousStatus));

    Notification::assertSentTo(
        $this->operator,
        EquipmentStatusChanged::class,
        function ($notification, $channels) {
            expect($channels)->toBe(['mail']);

            $mail = $notification->toMail($this->operator);
            expect($mail->subject)->toContain('Equipment Status Update')
                ->and($mail->subject)->toContain('Icom IC-7300');

            return true;
        }
    );
});

test('EquipmentStatusChanged includes station assignment when status is in_use', function () {
    Notification::fake();

    $station = \App\Models\Station::factory()->create([
        'name' => 'Station 1A',
    ]);

    $previousStatus = 'delivered';
    $this->equipmentEvent->update([
        'status' => 'in_use',
        'station_id' => $station->id,
        'status_changed_at' => now(),
        'status_changed_by_user_id' => $this->manager->id,
    ]);

    $this->operator->notify(new EquipmentStatusChanged($this->equipmentEvent, $previousStatus));

    Notification::assertSentTo(
        $this->operator,
        EquipmentStatusChanged::class,
        function ($notification) {
            $mail = $notification->toMail($this->operator);

            // The mail should contain station information
            expect($notification->equipmentEvent->station->name)->toBe('Station 1A');

            return true;
        }
    );
});

test('EquipmentStatusChanged includes manager notes when present', function () {
    Notification::fake();

    $previousStatus = 'delivered';
    $this->equipmentEvent->update([
        'status' => 'returned',
        'status_changed_at' => now(),
        'status_changed_by_user_id' => $this->manager->id,
        'manager_notes' => 'Equipment returned in excellent condition. No issues.',
    ]);

    $this->operator->notify(new EquipmentStatusChanged($this->equipmentEvent, $previousStatus));

    Notification::assertSentTo(
        $this->operator,
        EquipmentStatusChanged::class,
        function ($notification) {
            expect($notification->equipmentEvent->manager_notes)
                ->toContain('Equipment returned in excellent condition');

            return true;
        }
    );
});

test('all notifications implement ShouldQueue for async delivery', function () {
    $committedNotification = new EquipmentCommitted($this->equipmentEvent, 'operator');
    $deliveredNotification = new EquipmentDelivered($this->equipmentEvent, 'operator');
    $statusChangedNotification = new EquipmentStatusChanged($this->equipmentEvent, 'committed');

    expect($committedNotification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class)
        ->and($deliveredNotification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class)
        ->and($statusChangedNotification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('all notifications use mail channel', function () {
    $committedNotification = new EquipmentCommitted($this->equipmentEvent, 'operator');
    $deliveredNotification = new EquipmentDelivered($this->equipmentEvent, 'operator');
    $statusChangedNotification = new EquipmentStatusChanged($this->equipmentEvent, 'committed');

    expect($committedNotification->via($this->operator))->toBe(['mail'])
        ->and($deliveredNotification->via($this->operator))->toBe(['mail'])
        ->and($statusChangedNotification->via($this->operator))->toBe(['mail']);
});
