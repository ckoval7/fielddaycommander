<?php

use App\DTOs\ExternalContactDto;
use App\Exceptions\InvalidExternalContactException;
use App\Livewire\Logbook\ContactEditor;
use App\Livewire\Logging\LoggingInterface;
use App\Livewire\Logging\TranscribeInterface;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoint;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\ExternalContactHandler;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    Permission::findOrCreate('log-contacts', 'web');
    Permission::findOrCreate('edit-contacts', 'web');
    $operator = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $operator->givePermissionTo('log-contacts');

    $this->user = User::factory()->create();
    $this->user->assignRole($operator);
    $this->user->givePermissionTo('edit-contacts');
    $this->actingAs($this->user);

    $fieldDay = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);

    $this->event = Event::factory()->create([
        'event_type_id' => $fieldDay->id,
        'rules_version' => '2025',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->config = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    $this->station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'is_gota' => false]);

    $this->band = Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.175, 'allowed_fd' => true, 'sort_order' => 4]);
    $this->otherBand = Band::firstOrCreate(['name' => '40m'], ['meters' => 40, 'frequency_mhz' => 7.1, 'allowed_fd' => true, 'sort_order' => 3]);
    $this->cw = Mode::firstOrCreate(['name' => 'CW'], ['category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
    $this->phone = Mode::firstOrCreate(['name' => 'Phone'], ['category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->section = Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true]);

    // The rulebook's own override, so points provably come from the rule set rather than a hard-coded fallback.
    ModeRulePoint::create(['event_type_id' => $fieldDay->id, 'rules_version' => '2025', 'mode_id' => $this->cw->id, 'points' => 4]);

    $this->session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->cw->id,
        'qso_count' => 2,
    ]);
});

/**
 * An original W1AW 20m CW contact and a later duplicate of it.
 *
 * @return array{0: Contact, 1: Contact}
 */
function originalAndDupe(object $test, array $dupeOverrides = []): array
{
    $attributes = [
        'event_configuration_id' => $test->config->id,
        'operating_session_id' => $test->session->id,
        'logger_user_id' => $test->user->id,
        'band_id' => $test->band->id,
        'mode_id' => $test->cw->id,
        'section_id' => $test->section->id,
        'callsign' => 'W1AW',
        'exchange_class' => '3A',
    ];

    $original = Contact::factory()->create($attributes + ['qso_time' => now()->subHours(2), 'points' => 4, 'is_duplicate' => false]);
    $dupe = Contact::factory()->create(array_merge($attributes, [
        'qso_time' => now()->subHour(),
        'points' => 0,
        'is_duplicate' => true,
        'duplicate_of_contact_id' => $original->id,
    ], $dupeOverrides));

    return [$original, $dupe];
}

test('deleting the original in the logbook promotes its dupe with rule-set points', function () {
    [$original, $dupe] = originalAndDupe($this);

    Livewire::test(ContactEditor::class)->call('deleteContact', $original->id);

    $dupe->refresh();
    expect($dupe->is_duplicate)->toBeFalse()
        ->and($dupe->duplicate_of_contact_id)->toBeNull()
        ->and($dupe->points)->toBe(4);
});

test('restoring a deleted original demotes the contact promoted in its place', function () {
    [$original, $dupe] = originalAndDupe($this);

    Livewire::test(ContactEditor::class)->call('deleteContact', $original->id);
    Livewire::test(ContactEditor::class)->call('restoreContact', $original->id);

    $original->refresh();
    $dupe->refresh();
    expect($original->is_duplicate)->toBeFalse()
        ->and($original->points)->toBe(4)
        ->and($dupe->is_duplicate)->toBeTrue()
        ->and($dupe->duplicate_of_contact_id)->toBe($original->id)
        ->and($dupe->points)->toBe(0);
});

test('editing the original to another callsign promotes the dupe and links a new dupe', function () {
    [$original, $dupe] = originalAndDupe($this);

    Livewire::test(ContactEditor::class)
        ->call('openEdit', $original->id)
        ->set('callsign', 'k2abc')
        ->call('save')
        ->assertHasNoErrors();

    $original->refresh();
    $dupe->refresh();
    expect($original->callsign)->toBe('K2ABC')
        ->and($original->is_duplicate)->toBeFalse()
        ->and($original->points)->toBe(4)
        ->and($dupe->is_duplicate)->toBeFalse()
        ->and($dupe->points)->toBe(4);
});

test('editing a contact onto an earlier contact makes it a dupe pointing at that contact', function () {
    [$original] = originalAndDupe($this);
    $later = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->cw->id,
        'section_id' => $this->section->id,
        'callsign' => 'K9ZZZ',
        'exchange_class' => '1A',
        'qso_time' => now()->subMinutes(30),
        'points' => 4,
    ]);

    Livewire::test(ContactEditor::class)
        ->call('openEdit', $later->id)
        ->set('callsign', 'W1AW')
        ->call('save');

    $later->refresh();
    expect($later->is_duplicate)->toBeTrue()
        ->and($later->duplicate_of_contact_id)->toBe($original->id)
        ->and($later->points)->toBe(0);
});

test('editing a dupe onto another band reprices it as an original', function () {
    [, $dupe] = originalAndDupe($this);

    Livewire::test(ContactEditor::class)
        ->call('openEdit', $dupe->id)
        ->set('bandId', $this->otherBand->id)
        ->call('save');

    $dupe->refresh();
    expect($dupe->is_duplicate)->toBeFalse()
        ->and($dupe->duplicate_of_contact_id)->toBeNull()
        ->and($dupe->points)->toBe(4);
});

test('the logbook editor rejects a qso time outside the event period', function () {
    [$original] = originalAndDupe($this);

    Livewire::test(ContactEditor::class)
        ->call('openEdit', $original->id)
        ->set('qsoTime', $this->event->end_time->copy()->addHour()->format('Y-m-d H:i'))
        ->call('save')
        ->assertHasErrors('qsoTime');
});

test('deleting the original from the live logger promotes its dupe', function () {
    [$original, $dupe] = originalAndDupe($this);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->call('deleteContact', $original->id);

    expect($dupe->fresh()->is_duplicate)->toBeFalse()
        ->and($dupe->fresh()->points)->toBe(4)
        ->and($this->session->fresh()->qso_count)->toBe(1);
});

test('restoring the original from the live logger demotes the promoted contact', function () {
    [$original, $dupe] = originalAndDupe($this);

    $component = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session]);
    $component->call('deleteContact', $original->id);
    $component->call('restoreContact', $original->id);

    expect($dupe->fresh()->is_duplicate)->toBeTrue()
        ->and($dupe->fresh()->duplicate_of_contact_id)->toBe($original->id)
        ->and($original->fresh()->points)->toBe(4);
});

test('updating a live contact onto an earlier callsign records which contact it duplicates', function () {
    [$original] = originalAndDupe($this);
    $other = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->cw->id,
        'section_id' => $this->section->id,
        'callsign' => 'K9ZZZ',
        'exchange_class' => '1A',
        'qso_time' => now()->subMinutes(10),
        'points' => 4,
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->call('updateContact', $other->id, 'W1AW 3A CT');

    $other->refresh();
    expect($other->is_duplicate)->toBeTrue()
        ->and($other->duplicate_of_contact_id)->toBe($original->id)
        ->and($other->points)->toBe(0);
});

test('an external replace restores points to a former dupe and reprices a mode change', function () {
    $handler = app(ExternalContactHandler::class);
    $dto = fn (string $callsign, $timestamp, string $mode, string $externalId, bool $isReplace = false) => new ExternalContactDto(
        callsign: $callsign,
        timestamp: $timestamp,
        source: 'n1mm',
        modeName: $mode,
        stationIdentifier: 'PC1',
        frequencyHz: 14025000,
        sectionCode: 'CT',
        externalId: $externalId,
        isReplace: $isReplace,
    );

    $first = $handler->handleContact($dto('W1AW', now()->subHours(2), 'CW', 'ext-1'), $this->config);
    $second = $handler->handleContact($dto('W1AW', now()->subHour(), 'CW', 'ext-2'), $this->config);

    expect($second->fresh()->is_duplicate)->toBeTrue();

    // The operator corrects the second QSO to another callsign: no longer a dupe.
    $handler->handleReplace($dto('K2ABC', now()->subHour(), 'CW', 'ext-2', true), $this->config);
    expect($second->fresh()->is_duplicate)->toBeFalse()
        ->and($second->fresh()->points)->toBe(4);

    // And the first QSO is corrected to Phone: repriced for its new mode.
    $handler->handleReplace($dto('W1AW', now()->subHours(2), 'SSB', 'ext-1', true), $this->config);
    expect($first->fresh()->mode_id)->toBe($this->phone->id)
        ->and($first->fresh()->points)->toBe(1);
});

test('external contacts without band or mode are stored at zero points', function () {
    $contact = app(ExternalContactHandler::class)->handleContact(
        new ExternalContactDto(callsign: 'W1AW', timestamp: now()->subHour(), source: 'n1mm', stationIdentifier: 'PC1', externalId: 'ext-nb'),
        $this->config,
    );

    expect($contact->band_id)->toBeNull()
        ->and($contact->is_duplicate)->toBeFalse()
        ->and($contact->points)->toBe(0);
});

test('external replace and delete only touch contacts of the same event', function () {
    $otherConfig = EventConfiguration::factory()->create(['event_id' => Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ])->id]);
    $foreign = Contact::factory()->create([
        'event_configuration_id' => $otherConfig->id,
        'callsign' => 'K5OTH',
        'external_id' => 'shared-id',
        'qso_time' => now()->subHour(),
    ]);

    $handler = app(ExternalContactHandler::class);
    $dto = new ExternalContactDto(callsign: 'W1AW', timestamp: now()->subHour(), source: 'n1mm', stationIdentifier: 'PC1', externalId: 'shared-id', frequencyHz: 14025000, modeName: 'CW');

    $handler->handleReplace($dto, $this->config);
    $handler->handleDelete($dto, $this->config);
    $created = $handler->handleContact($dto, $this->config);

    expect($foreign->fresh()->callsign)->toBe('K5OTH')
        ->and($foreign->fresh()->trashed())->toBeFalse()
        ->and($created->id)->not->toBe($foreign->id)
        ->and($created->event_configuration_id)->toBe($this->config->id);
});

test('external contacts with an invalid callsign are dropped', function () {
    app(ExternalContactHandler::class)->handleContact(
        new ExternalContactDto(callsign: "W1AW'><script>", timestamp: now()->subHour(), source: 'n1mm', stationIdentifier: 'PC1'),
        $this->config,
    );
})->throws(InvalidExternalContactException::class);

test('a transcribed contact inside the clock-drift allowance but before the event earns nothing and is not the original', function () {
    $early = $this->event->start_time->copy()->subMinutes(3);
    $inWindow = $this->event->start_time->copy()->addHour();

    $component = Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->cw->id);

    $component->set('workingDate', $inWindow->format('Y-m-d'))
        ->set('contactTime', $inWindow->format('H:i'))
        ->set('exchangeInput', 'W1AW 3A CT')
        ->call('logContact')
        ->assertSet('parseError', '');

    $component->set('workingDate', $early->format('Y-m-d'))
        ->set('contactTime', $early->format('H:i'))
        ->set('exchangeInput', 'W1AW 3A CT')
        ->call('logContact')
        ->assertSet('parseError', '');

    $earlyContact = Contact::where('callsign', 'W1AW')->orderBy('qso_time')->first();
    $genuine = Contact::where('callsign', 'W1AW')->orderByDesc('qso_time')->first();

    expect($earlyContact->points)->toBe(0)
        ->and($earlyContact->is_duplicate)->toBeFalse()
        ->and($genuine->is_duplicate)->toBeFalse()
        ->and($genuine->points)->toBe(4);
});

test('transcribed contacts entered by someone else need edit-contacts to change', function () {
    $transcriptionSession = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'is_transcription' => true,
        'start_time' => $this->event->start_time,
        'end_time' => $this->event->end_time,
    ]);
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $transcriptionSession->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->cw->id,
        'section_id' => $this->section->id,
        'callsign' => 'W1AW',
        'exchange_class' => '3A',
        'is_transcribed' => true,
        'qso_time' => now()->subHour(),
    ]);

    $operator = User::factory()->create();
    $operator->assignRole('Operator');
    $this->actingAs($operator);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('updateContact', $contact->id, 'K2ABC 3A CT')
        ->assertForbidden();

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('deleteContact', $contact->id)
        ->assertForbidden();

    expect($contact->fresh()->callsign)->toBe('W1AW')
        ->and($contact->fresh()->trashed())->toBeFalse();
});

test('removing an original contact row nulls the link on its dupes', function () {
    [$original, $dupe] = originalAndDupe($this);

    $original->forceDelete();

    expect($dupe->fresh()->duplicate_of_contact_id)->toBeNull();
});
