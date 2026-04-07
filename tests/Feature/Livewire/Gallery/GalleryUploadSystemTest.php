<?php

use App\Livewire\Gallery\GalleryUpload;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Image;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('system user cannot upload photos', function () {
    Storage::fake('public');

    $systemUser = User::factory()->create([
        'call_sign' => User::SYSTEM_CALL_SIGN,
    ]);

    $event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);
    Setting::set('active_event_id', $event->id);

    $this->actingAs($systemUser);

    Livewire::test(GalleryUpload::class, ['eventConfiguration' => $eventConfig])
        ->set('photo', UploadedFile::fake()->image('test.jpg'))
        ->set('caption', 'Test photo')
        ->call('save')
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'] ?? '', 'SYSTEM account'));

    expect(Image::count())->toBe(0);
});
