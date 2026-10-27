<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Logbook\LogbookBrowser;
use App\Livewire\SectionMap;
use App\Models\Contact;
use App\Models\Equipment;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Image;
use App\Models\Message;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Setting::set('setup_completed', 'true');
    $this->seed(PermissionSeeder::class);
    $this->user = User::factory()->create();
    $this->user->givePermissionTo(Permission::all());
    $this->actingAs($this->user);

    $this->event = Event::factory()->create([
        'name' => 'Accessible Field Day',
        'year' => 2026,
        'rules_version' => '2026',
        'start_time' => now()->subHour(),
        'end_time' => now()->addDay(),
    ]);
    $this->eventConfiguration = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    $this->station = Station::factory()->create(['event_configuration_id' => $this->eventConfiguration->id]);
    $this->equipment = Equipment::factory()->create(['owner_user_id' => $this->user->id]);
    $this->message = Message::factory()->create([
        'event_configuration_id' => $this->eventConfiguration->id,
        'user_id' => $this->user->id,
    ]);
});

it('gives full pages one primary heading and a specific browser title', function (string $route, string $title, Closure $parameters) {
    $response = $this->get(route($route, $parameters->call($this)))->assertOk();
    $html = $response->getContent();
    preg_match_all('/<h1\b[^>]*>.*?<\/h1>/s', $html, $headings);
    preg_match('/<title>(.*?)<\/title>/s', $html, $browserTitle);

    expect($headings[0])->toHaveCount(1)
        ->and(html_entity_decode($browserTitle[1] ?? ''))->toContain($title);
})->with([
    'events' => ['events.index', 'Events', fn (): array => []],
    'create event' => ['events.create', 'Create Event', fn (): array => []],
    'edit event' => ['events.edit', 'Edit Event', fn (): array => ['eventId' => $this->event->id]],
    'clone event' => ['events.clone', 'Clone Event', fn (): array => ['eventId' => $this->event->id]],
    'event dashboard' => ['events.show', 'Accessible Field Day', fn (): array => ['event' => $this->event]],
    'stations' => ['stations.index', 'Stations', fn (): array => []],
    'create station' => ['stations.create', 'Create Station', fn (): array => []],
    'edit station' => ['stations.edit', 'Edit Station', fn (): array => ['station' => $this->station]],
    'gallery' => ['gallery.index', 'Photo Gallery', fn (): array => []],
    'album' => ['gallery.show', 'Photo Gallery', fn (): array => ['eventConfiguration' => $this->eventConfiguration]],
    'upload' => ['gallery.upload', 'Upload Photo', fn (): array => ['eventConfiguration' => $this->eventConfiguration]],
    'reports' => ['reports.index', 'Reports', fn (): array => []],
    'section map' => ['section-map', 'Section Map', fn (): array => []],
    'profile' => ['profile', 'Profile Settings', fn (): array => []],
    'settings' => ['settings.index', 'Settings', fn (): array => []],
    'my equipment' => ['equipment.index', 'My Equipment', fn (): array => []],
    'catalog' => ['equipment.all', 'Equipment Catalog', fn (): array => []],
    'club equipment' => ['equipment.club', 'Club Equipment', fn (): array => []],
    'add equipment' => ['equipment.create', 'Add Equipment', fn (): array => []],
    'edit equipment' => ['equipment.edit', 'Edit Equipment', fn (): array => ['equipment' => $this->equipment]],
    'event equipment' => ['events.equipment.dashboard', 'Equipment', fn (): array => ['event' => $this->event]],
    'messages' => ['events.messages.index', 'Message Traffic', fn (): array => ['event' => $this->event]],
    'create message' => ['events.messages.create', 'Message', fn (): array => ['event' => $this->event]],
    'edit message' => ['events.messages.edit', 'Message', fn (): array => ['event' => $this->event, 'message' => $this->message]],
    'bulletin' => ['events.w1aw-bulletin', 'W1AW Bulletin', fn (): array => []],
    'logbook' => ['logbook.index', 'Logbook', fn (): array => []],
    'scoring' => ['scoring.index', 'Scoring', fn (): array => []],
    'guestbook' => ['guestbook.index', 'Guestbook', fn (): array => []],
    'guestbook manager' => ['events.guestbook', 'Guestbook Manager', fn (): array => ['event' => $this->event]],
    'schedule' => ['schedule.index', 'Shift Schedule', fn (): array => []],
    'my shifts' => ['schedule.my-shifts', 'My Shifts', fn (): array => []],
    'shift dashboard' => ['schedule.dashboard', 'Shift Dashboard', fn (): array => []],
    'manage schedule' => ['schedule.manage', 'Manage Schedule', fn (): array => []],
    'site safety' => ['site-safety.index', 'Site Safety Checklist', fn (): array => []],
    'manage safety' => ['site-safety.manage', 'Manage Safety Checklist', fn (): array => []],
    'users' => ['users.index', 'User Management', fn (): array => []],
    'audit logs' => ['admin.audit-logs', 'Audit Logs', fn (): array => []],
    'external loggers' => ['admin.external-loggers', 'External Loggers', fn (): array => []],
    'ADIF import' => ['admin.import-adif', 'Import ADIF', fn (): array => []],
]);

it('names event summaries without nesting interactive controls', function () {
    $html = $this->get(route('events.index'))->assertOk()->getContent();

    preg_match_all('/<summary\b[^>]*aria-label="Actions for Accessible Field Day"[^>]*>(.*?)<\/summary>/s', $html, $summaries);
    expect($summaries[1])->toHaveCount(2);
    foreach ($summaries[1] as $summary) {
        expect($summary)->not->toMatch('/<(button|a)\b/');
    }
});

it('names contact actions and selection and selects only the current page', function () {
    Contact::factory()->count(3)->create([
        'event_configuration_id' => $this->eventConfiguration->id,
        'callsign' => 'W1AW',
    ]);

    $component = Livewire::test(LogbookBrowser::class)
        ->set('perPage', 2)
        ->assertSeeHtml('aria-label="Edit contact W1AW"')
        ->assertSeeHtml('aria-label="Delete contact W1AW"')
        ->assertSeeHtml('aria-label="Select contact W1AW"')
        ->assertSeeHtml('aria-label="Select all contacts on this page"')
        ->call('toggleSelectAll');

    expect($component->get('selectedIds'))->toHaveCount(2);
    $component->call('toggleSelectAll')->assertSet('selectedIds', []);
});

it('exposes section details without pointer tooltips', function () {
    $worked = Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'is_active' => true]);
    $worked->update(['is_active' => true]);
    Section::factory()->create(['code' => 'OH', 'name' => 'Ohio', 'is_active' => true]);
    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->eventConfiguration->id,
        'section_id' => $worked->id,
        'is_duplicate' => false,
    ]);

    $html = Livewire::test(SectionMap::class)->html();
    preg_match('/<ul\b[^>]*aria-label="Section details"[^>]*>(.*?)<\/ul>/s', $html, $list);
    $text = html_entity_decode(strip_tags($list[1] ?? ''));

    expect($text)->toContain('CT: Connecticut', 'Worked', '1 QSOs', 'OH: Ohio', 'Not worked', 'Bands:', 'Modes:', 'Last worked:', 'Never')
        ->and($text)->toContain($contact->band->name, $contact->mode->name)
        ->and($html)->toContain(':aria-pressed=');
});

it('provides scoped headers in the landing band and mode grid', function () {
    Contact::factory()->create(['event_configuration_id' => $this->eventConfiguration->id]);

    $html = $this->get(route('public.landing'))->assertOk()->getContent();
    expect($html)->toContain('scope="col"', 'scope="row"', 'aria-controls="sections-', ':aria-expanded=');
});

it('uses keyboard buttons and selected state for profile tabs', function () {
    $this->get(route('profile'))->assertOk()
        ->assertSee('type="button" role="tab"', false)
        ->assertSee('aria-selected="true"', false)
        ->assertSee('role="tabpanel"', false)
        ->assertSee('x-on:keydown.arrow-right.prevent', false);
});

it('keeps the kiosk heading when the guestbook opens', function () {
    $this->eventConfiguration->update(['guestbook_enabled' => true]);

    $html = $this->get(route('guestbook.kiosk'))->assertOk()->getContent();
    preg_match_all('/<h1\b[^>]*>.*?<\/h1>/s', $html, $headings);
    expect($headings[0])->toHaveCount(1)
        ->and(strip_tags($headings[0][0]))->toBe('Sign Our Guestbook');
});

it('gives browser-printed messages a primary heading', function () {
    $this->get(route('events.messages.print', [$this->event, $this->message]))
        ->assertOk()
        ->assertSee('<h1 class="no-print">Messages</h1>', false);
});

it('keeps text-bearing page elements above the muted contrast floor', function () {
    $violations = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());
        if (! str_ends_with($path, '.blade.php') || preg_match('#^(components/|auth/|livewire/(logging|components)/|reports/|equipment/reports/)#', $path)) {
            continue;
        }

        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());
        preg_match_all(<<<'REGEX'
~<(?:p|span|div|th|td|h[1-6])\b(?:[^'"<>]|"[^"]*"|'[^']*')*>~
REGEX, $source, $tags);

        foreach ($tags[0] as $tag) {
            if (str_contains($tag, 'aria-hidden="true"') || str_contains($tag, 'disabled')) {
                continue;
            }

            if (preg_match('~text-base-content/(?:20|25|30|40|50|55)(?!\d)~', $tag)) {
                $violations[] = $path.': '.$tag;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('exposes selected message filters without relying on button colour', function () {
    $this->get(route('events.messages.index', $this->event))->assertOk()
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('aria-pressed="false"', false);
});

it('gives gallery thumbnails keyboard controls', function () {
    Image::factory()->create([
        'event_configuration_id' => $this->eventConfiguration->id,
        'caption' => 'Club station',
    ]);

    $this->get(route('gallery.show', $this->eventConfiguration))->assertOk()
        ->assertSee('aria-label="Open photo: Club station"', false)
        ->assertSee('type="button" class="absolute inset-0', false);
});
