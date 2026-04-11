<?php

use App\Livewire\SectionMap;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    $this->band20 = Band::firstOrCreate(
        ['name' => '20m'],
        ['meters' => 20, 'frequency_mhz' => 14.175]
    );
    $this->band40 = Band::firstOrCreate(
        ['name' => '40m'],
        ['meters' => 40, 'frequency_mhz' => 7.175]
    );
    $this->modeSSB = Mode::firstOrCreate(
        ['name' => 'SSB'],
        ['category' => 'Phone']
    );
    $this->modeCW = Mode::firstOrCreate(
        ['name' => 'CW'],
        ['category' => 'CW']
    );

    $this->sectionCT = Section::firstOrCreate(
        ['code' => 'CT'],
        ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true]
    );
    $this->sectionOH = Section::firstOrCreate(
        ['code' => 'OH'],
        ['name' => 'Ohio', 'region' => 'W8', 'country' => 'US', 'is_active' => true]
    );

    $this->user = User::factory()->create();
    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
    ]);
});

test('section map renders with no event gracefully', function () {
    Event::query()->delete();

    Livewire\Livewire::test(SectionMap::class)
        ->assertSee('No active event');
});

test('section map renders with active event', function () {
    Livewire\Livewire::test(SectionMap::class)
        ->assertSee('Section Map')
        ->assertDontSee('No active event');
});

test('section data includes QSO counts grouped by section code', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
    ]);

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $session->id,
        'section_id' => $this->sectionCT->id,
        'band_id' => $this->band20->id,
        'mode_id' => $this->modeSSB->id,
        'logger_user_id' => $this->user->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->count(1)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $session->id,
        'section_id' => $this->sectionOH->id,
        'band_id' => $this->band40->id,
        'mode_id' => $this->modeCW->id,
        'logger_user_id' => $this->user->id,
        'is_duplicate' => false,
    ]);

    $component = Livewire\Livewire::test(SectionMap::class);

    $sectionData = $component->viewData('sectionData');

    expect($sectionData['CT']['count'])->toBe(3)
        ->and($sectionData['CT']['bands'])->toContain('20m')
        ->and($sectionData['CT']['modes'])->toContain('SSB')
        ->and($sectionData['OH']['count'])->toBe(1)
        ->and($sectionData['OH']['bands'])->toContain('40m')
        ->and($sectionData['OH']['modes'])->toContain('CW');
});

test('section data excludes duplicate contacts', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
    ]);

    Contact::factory()->count(2)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $session->id,
        'section_id' => $this->sectionCT->id,
        'band_id' => $this->band20->id,
        'mode_id' => $this->modeSSB->id,
        'logger_user_id' => $this->user->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $session->id,
        'section_id' => $this->sectionCT->id,
        'band_id' => $this->band20->id,
        'mode_id' => $this->modeSSB->id,
        'logger_user_id' => $this->user->id,
        'is_duplicate' => true,
    ]);

    $component = Livewire\Livewire::test(SectionMap::class);
    $sectionData = $component->viewData('sectionData');

    expect($sectionData['CT']['count'])->toBe(2);
});

test('section data aggregates multiple bands and modes', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $session->id,
        'section_id' => $this->sectionCT->id,
        'band_id' => $this->band20->id,
        'mode_id' => $this->modeSSB->id,
        'logger_user_id' => $this->user->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $session->id,
        'section_id' => $this->sectionCT->id,
        'band_id' => $this->band40->id,
        'mode_id' => $this->modeCW->id,
        'logger_user_id' => $this->user->id,
        'is_duplicate' => false,
    ]);

    $component = Livewire\Livewire::test(SectionMap::class);
    $sectionData = $component->viewData('sectionData');

    expect($sectionData['CT']['count'])->toBe(2)
        ->and($sectionData['CT']['bands'])->toContain('20m', '40m')
        ->and($sectionData['CT']['modes'])->toContain('SSB', 'CW');
});

test('summary stats include total QSOs and sections worked', function () {
    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
    ]);

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $session->id,
        'section_id' => $this->sectionCT->id,
        'band_id' => $this->band20->id,
        'mode_id' => $this->modeSSB->id,
        'logger_user_id' => $this->user->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $session->id,
        'section_id' => $this->sectionOH->id,
        'band_id' => $this->band40->id,
        'mode_id' => $this->modeCW->id,
        'logger_user_id' => $this->user->id,
        'is_duplicate' => false,
    ]);

    $component = Livewire\Livewire::test(SectionMap::class);

    expect($component->viewData('totalQsos'))->toBe(4)
        ->and($component->viewData('sectionsWorked'))->toBe(2);
});

test('section map page is publicly accessible', function () {
    $this->get('/section-map')
        ->assertOk()
        ->assertSeeLivewire(SectionMap::class);
});
