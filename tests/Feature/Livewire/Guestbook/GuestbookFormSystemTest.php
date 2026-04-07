<?php

use App\Livewire\Guestbook\GuestbookForm;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

test('system user cannot sign the guestbook', function () {
    $systemUser = User::factory()->create([
        'call_sign' => User::SYSTEM_CALL_SIGN,
    ]);

    $event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'guestbook_enabled' => true,
    ]);
    Setting::set('active_event_id', $event->id);

    $this->actingAs($systemUser);

    Livewire::test(GuestbookForm::class)
        ->set('name', 'System Admin')
        ->set('callsign', 'SYSTEM')
        ->set('presence_type', 'online')
        ->set('visitor_category', 'general_public')
        ->call('save')
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'], 'SYSTEM account'));

    expect(GuestbookEntry::count())->toBe(0);
});
