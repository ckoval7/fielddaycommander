<?php

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\User;
use App\Notifications\Equipment\EquipmentCancelled;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->manager = User::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'call_sign' => 'K3UHF',
        'email' => 'manager@example.com',
    ]);

    $this->operator = User::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'call_sign' => 'W1AW',
        'email' => 'operator@example.com',
    ]);

    $this->event = Event::factory()->create([
        'name' => 'Field Day 2025',
    ]);

    $this->equipment = Equipment::factory()->create([
        'owner_user_id' => $this->operator->id,
        'make' => 'Icom',
        'model' => 'IC-7300',
        'type' => 'radio',
        'description' => null,
    ]);

    $this->equipmentEvent = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'cancelled',
        'committed_at' => now()->subDays(3),
        'status_changed_at' => now(),
        'expected_delivery_at' => null,
        'manager_notes' => null,
    ]);
});

test('EquipmentCancelled uses mail channel', function () {
    $notification = new EquipmentCancelled($this->equipmentEvent);

    expect($notification->via($this->manager))->toBe(['mail']);
});

test('EquipmentCancelled implements ShouldQueue', function () {
    $notification = new EquipmentCancelled($this->equipmentEvent);

    expect($notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('EquipmentCancelled mail subject contains equipment name and operator call sign', function () {
    Notification::fake();

    $this->manager->notify(new EquipmentCancelled($this->equipmentEvent));

    Notification::assertSentTo(
        $this->manager,
        EquipmentCancelled::class,
        function ($notification, $channels) {
            expect($channels)->toBe(['mail']);

            $mail = $notification->toMail($this->manager);
            expect($mail->subject)->toContain('Equipment Commitment Cancelled')
                ->and($mail->subject)->toContain('Icom IC-7300')
                ->and($mail->subject)->toContain('W1AW');

            return true;
        }
    );
});

test('EquipmentCancelled mail contains equipment and event details', function () {
    Notification::fake();

    $this->manager->notify(new EquipmentCancelled($this->equipmentEvent));

    Notification::assertSentTo(
        $this->manager,
        EquipmentCancelled::class,
        function ($notification) {
            $mail = $notification->toMail($this->manager);
            $introLines = collect($mail->introLines);
            $allLines = $introLines->implode(' ');

            expect($allLines)->toContain('Field Day 2025')
                ->and($allLines)->toContain('Icom IC-7300')
                ->and($allLines)->toContain('radio');

            return true;
        }
    );
});

test('EquipmentCancelled mail contains operator contact information', function () {
    $notification = new EquipmentCancelled($this->equipmentEvent);

    $mail = $notification->toMail($this->manager);
    $allLines = collect($mail->introLines)->implode(' ');

    expect($allLines)->toContain('John Doe')
        ->and($allLines)->toContain('W1AW')
        ->and($allLines)->toContain('operator@example.com');
});

test('EquipmentCancelled mail does not include expected delivery when not set', function () {
    $notification = new EquipmentCancelled($this->equipmentEvent);

    $mail = $notification->toMail($this->manager);
    $allLines = collect($mail->introLines)->implode(' ');

    expect($allLines)->not->toContain('Was Expected');
});

test('EquipmentCancelled mail includes expected delivery when set', function () {
    $this->equipmentEvent->update([
        'expected_delivery_at' => now()->addDays(5),
    ]);

    $notification = new EquipmentCancelled($this->equipmentEvent->fresh());

    $mail = $notification->toMail($this->manager);
    $allLines = collect($mail->introLines)->implode(' ');

    expect($allLines)->toContain('Was Expected');
});

test('EquipmentCancelled mail does not include description when not set', function () {
    $notification = new EquipmentCancelled($this->equipmentEvent);

    $mail = $notification->toMail($this->manager);
    $allLines = collect($mail->introLines)->implode(' ');

    expect($allLines)->not->toContain('Description');
});

test('EquipmentCancelled mail includes description when present', function () {
    $this->equipment->update(['description' => 'High-end HF transceiver with built-in tuner']);

    $notification = new EquipmentCancelled($this->equipmentEvent->fresh());

    $mail = $notification->toMail($this->manager);
    $allLines = collect($mail->introLines)->implode(' ');

    expect($allLines)->toContain('Description')
        ->and($allLines)->toContain('High-end HF transceiver with built-in tuner');
});

test('EquipmentCancelled mail does not include manager notes when not set', function () {
    $notification = new EquipmentCancelled($this->equipmentEvent);

    $mail = $notification->toMail($this->manager);
    $allLines = collect($mail->introLines)->implode(' ');

    expect($allLines)->not->toContain('Notes from Cancellation');
});

test('EquipmentCancelled mail includes manager notes when present', function () {
    $this->equipmentEvent->update(['manager_notes' => 'Operator withdrew due to health issues']);

    $notification = new EquipmentCancelled($this->equipmentEvent->fresh());

    $mail = $notification->toMail($this->manager);
    $allLines = collect($mail->introLines)->implode(' ');

    expect($allLines)->toContain('Notes from Cancellation')
        ->and($allLines)->toContain('Operator withdrew due to health issues');
});

test('EquipmentCancelled toArray returns correct structure', function () {
    $notification = new EquipmentCancelled($this->equipmentEvent);

    $array = $notification->toArray($this->manager);

    expect($array)
        ->toHaveKey('equipment_event_id', $this->equipmentEvent->id)
        ->toHaveKey('equipment_id', $this->equipment->id)
        ->toHaveKey('event_id', $this->event->id)
        ->toHaveKey('status', 'cancelled')
        ->toHaveKey('cancelled_at')
        ->toHaveKey('was_committed_at');
});

test('EquipmentCancelled toArray cancelled_at matches status_changed_at', function () {
    $changedAt = now()->subHour();
    $this->equipmentEvent->update(['status_changed_at' => $changedAt]);

    $notification = new EquipmentCancelled($this->equipmentEvent->fresh());
    $array = $notification->toArray($this->manager);

    expect($array['cancelled_at']->toDateTimeString())->toBe($changedAt->toDateTimeString());
});

test('EquipmentCancelled toArray was_committed_at matches committed_at', function () {
    $committedAt = now()->subDays(3);
    $this->equipmentEvent->update(['committed_at' => $committedAt]);

    $notification = new EquipmentCancelled($this->equipmentEvent->fresh());
    $array = $notification->toArray($this->manager);

    expect($array['was_committed_at']->toDateTimeString())->toBe($committedAt->toDateTimeString());
});
