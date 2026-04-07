<?php

use App\Livewire\Messages\W1awBulletinForm;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\User;
use App\Models\W1awBulletin;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->seed([\Database\Seeders\EventTypeSeeder::class, \Database\Seeders\BonusTypeSeeder::class]);
    Permission::firstOrCreate(['name' => 'manage-bulletins']);
    Permission::firstOrCreate(['name' => 'log-contacts']);
});

test('system user cannot save a bulletin', function () {
    $systemUser = User::factory()->create([
        'call_sign' => User::SYSTEM_CALL_SIGN,
    ]);
    $systemUser->givePermissionTo(['manage-bulletins', 'log-contacts']);

    $event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);
    Setting::set('active_event_id', $event->id);

    $this->actingAs($systemUser);

    Livewire::test(W1awBulletinForm::class, ['event' => $event])
        ->set('frequency', '7.047')
        ->set('mode', 'cw')
        ->set('receivedAt', appNow()->format('Y-m-d\TH:i'))
        ->set('bulletinText', 'Test bulletin text')
        ->call('save')
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'] ?? '', 'SYSTEM account'));

    expect(W1awBulletin::count())->toBe(0);
});

test('system user cannot delete a bulletin', function () {
    $systemUser = User::factory()->create([
        'call_sign' => User::SYSTEM_CALL_SIGN,
    ]);
    $systemUser->givePermissionTo(['manage-bulletins', 'log-contacts']);

    $event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);
    Setting::set('active_event_id', $event->id);

    $bulletin = W1awBulletin::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'user_id' => $systemUser->id,
    ]);

    $this->actingAs($systemUser);

    Livewire::test(W1awBulletinForm::class, ['event' => $event])
        ->call('deleteBulletin')
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'] ?? '', 'SYSTEM account'));

    expect(W1awBulletin::find($bulletin->id))->not->toBeNull();
});
