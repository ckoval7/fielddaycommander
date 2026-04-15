<?php

use App\Http\Controllers\AlbumExportController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\ContactSyncController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Equipment\EquipmentReportController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\LogbookController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SetupController;
use App\Livewire\Admin\AdifImport;
use App\Livewire\Admin\AuditLogViewer;
use App\Livewire\Admin\DeveloperTools;
use App\Livewire\Admin\ExternalLoggerManagement;
use App\Livewire\Equipment\AllEquipmentList;
use App\Livewire\Equipment\ClubEquipmentList;
use App\Livewire\Equipment\EquipmentForm;
use App\Livewire\Equipment\EquipmentList;
use App\Livewire\Equipment\EventEquipmentDashboard;
use App\Livewire\Events\EventDashboard;
use App\Livewire\Events\EventForm;
use App\Livewire\Events\EventsList;
use App\Livewire\Gallery\GalleryIndex;
use App\Livewire\Gallery\GalleryShow;
use App\Livewire\Gallery\GalleryUpload;
use App\Livewire\Guestbook\GuestbookManager;
use App\Livewire\Logging\LoggingInterface;
use App\Livewire\Logging\StationSelect;
use App\Livewire\Logging\TranscribeInterface;
use App\Livewire\Logging\TranscribeSelect;
use App\Livewire\Messages\MessageForm;
use App\Livewire\Messages\MessageTrafficIndex;
use App\Livewire\Messages\W1awBulletinForm;
use App\Livewire\Profile\UserProfile;
use App\Livewire\Reports\ReportsIndex;
use App\Livewire\Safety\ManageSafetyChecklist;
use App\Livewire\Safety\SiteSafetyChecklist;
use App\Livewire\Schedule\ManageSchedule;
use App\Livewire\Schedule\MyShifts;
use App\Livewire\Schedule\ScheduleTimeline;
use App\Livewire\Stations\StationForm;
use App\Livewire\Stations\StationsList;
use App\Livewire\Users\UserManagement;
use App\Livewire\Weather\WeatherDashboard;
use App\Models\Event;
use App\Models\Message;
use Illuminate\Support\Facades\Route;

// Setup Wizard Routes (exempt from setup check middleware)
Route::prefix('setup')->group(function () {
    Route::get('/welcome', [SetupController::class, 'welcome'])->name('setup.welcome');
    Route::post('/step-1', [SetupController::class, 'stepOne'])->name('setup.step-1');
    Route::get('/branding', [SetupController::class, 'branding'])->name('setup.branding');
    Route::post('/step-2', [SetupController::class, 'stepTwo'])->name('setup.step-2');
    Route::get('/preferences', [SetupController::class, 'preferences'])->name('setup.preferences');
    Route::post('/complete', [SetupController::class, 'complete'])->name('setup.complete');
});

// User Invitation Routes
Route::get('/register/invite/{token}', [InvitationController::class, 'show'])->name('invitation.show');
Route::post('/register/invite/{token}', [InvitationController::class, 'accept'])->name('invitation.accept');

// Registration Pending (approval required mode)
Route::view('/registration-pending', 'auth.registration-pending')
    ->name('registration.pending')
    ->middleware('guest');

// Dashboard System Routes
Route::get('/', [DashboardController::class, 'index'])
    ->name('dashboard');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard.alt');

Route::get('/public', [DashboardController::class, 'publicLanding'])
    ->name('public.landing');

Route::get('/dashboard/tv', [DashboardController::class, 'tv'])
    ->name('dashboard.tv');

// Profile Management
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profile', UserProfile::class)->name('profile');
});

// Contact Logging
Route::middleware(['auth', 'verified', 'can:log-contacts'])->group(function () {
    Route::get('/logging', StationSelect::class)->name('logging.station-select');
    Route::get('/logging/session/{operatingSession}', LoggingInterface::class)->name('logging.session');
    Route::get('/logging/transcribe', TranscribeSelect::class)->name('logging.transcribe.select');
    Route::get('/logging/transcribe/{station}', TranscribeInterface::class)->name('logging.transcribe.session');
});

// Contact sync endpoint (controller also checks session ownership)
Route::middleware(['auth', 'verified', 'can:log-contacts'])->group(function () {
    Route::post('/logging/contacts', [ContactSyncController::class, 'store'])->name('logging.contacts.store');
});

// Logbook Browser (public)
Route::get('/logbook', [LogbookController::class, 'index'])->name('logbook.index');
Route::get('/logbook/export', [LogbookController::class, 'export'])->name('logbook.export');

// Section Map (public)
Route::get('/section-map', function () {
    return view('section-map.index');
})->name('section-map');

// Event Management
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/scoring', function () {
        return view('scoring.index');
    })->name('scoring.index');
});

// Public Guestbook
Route::get('/guestbook', function () {
    return view('guestbook.index');
})->name('guestbook.index');

// Gallery
Route::get('/gallery', GalleryIndex::class)->name('gallery.index');
Route::get('/gallery/{eventConfiguration}', GalleryShow::class)->name('gallery.show');
Route::get('/gallery/{eventConfiguration}/upload', GalleryUpload::class)
    ->middleware(['auth', 'verified'])
    ->name('gallery.upload');
Route::get('/gallery/thumb/{image}', [GalleryController::class, 'thumbnail'])->name('gallery.thumb');
Route::get('/gallery/image/{image}', [GalleryController::class, 'image'])->name('gallery.image');

Route::middleware(['auth', 'verified', 'can:manage-images'])->group(function () {
    Route::post('/gallery/{eventConfiguration}/export', [AlbumExportController::class, 'store'])->name('album-export.store');
    Route::get('/gallery/{eventConfiguration}/export/{filename}', [AlbumExportController::class, 'download'])->name('album-export.download');
});

Route::middleware(['auth', 'verified', 'can:manage-bonuses'])->group(function () {
    Route::get('/bonuses', function () {
        return view('bonuses.index');
    })->name('bonuses.index');
});

Route::middleware(['auth', 'verified', 'can:view-stations'])->group(function () {
    Route::get('/stations', StationsList::class)->name('stations.index');
});

Route::middleware(['auth', 'verified', 'can:manage-stations'])->group(function () {
    Route::get('/stations/create', StationForm::class)->name('stations.create');
    Route::get('/stations/{station}/edit', StationForm::class)->name('stations.edit');
});

Route::middleware(['auth', 'verified', 'can:view-all-equipment'])->group(function () {
    Route::get('/equipment/all', AllEquipmentList::class)->name('equipment.all');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/equipment/club', ClubEquipmentList::class)->name('equipment.club');
});

Route::middleware(['auth', 'verified', 'can:manage-own-equipment'])->group(function () {
    Route::get('/equipment', EquipmentList::class)->name('equipment.index');
    Route::get('/equipment/create', EquipmentForm::class)->name('equipment.create');
    Route::get('/equipment/{equipment}/edit', EquipmentForm::class)->name('equipment.edit');
});

// Administration
Route::middleware(['auth', 'verified', 'can:view-events'])->group(function () {
    Route::get('/events', EventsList::class)->name('events.index');
});

Route::middleware(['auth', 'verified', 'can:create-events'])->group(function () {
    Route::get('/events/create', EventForm::class)->name('events.create');
});

Route::middleware(['auth', 'verified', 'can:edit-events'])->group(function () {
    Route::get('/events/{eventId}/edit', EventForm::class)->name('events.edit');
});

Route::middleware(['auth', 'verified', 'can:create-events'])->group(function () {
    Route::get('/events/{eventId}/clone', EventForm::class)->name('events.clone');
});

Route::middleware(['auth', 'verified', 'can:view-events'])->group(function () {
    Route::get('/events/{event}', EventDashboard::class)->name('events.show');
});

// Equipment Dashboard - requires manage-event-equipment OR view-all-equipment (checked in component)
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/events/{event}/equipment', EventEquipmentDashboard::class)->name('events.equipment.dashboard');
});

// Guestbook Management - requires manage-guestbook permission for editing/verifying entries
Route::middleware(['auth', 'verified', 'can:manage-guestbook'])->group(function () {
    Route::get('/events/{event}/guestbook', GuestbookManager::class)->name('events.guestbook');
});

// Message Traffic — batch print route MUST come before {message} wildcard to avoid capture
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/events/{event}/messages/print-all', function (Event $event) {
        $messages = Message::where('event_configuration_id', $event->eventConfiguration->id)
            ->orderBy('message_number')->get();

        return view('messages.print', ['event' => $event, 'messages' => $messages]);
    })->name('events.messages.print-all');

    Route::get('/events/{event}/messages/{message}/print', function (Event $event, Message $message) {
        return view('messages.print', ['event' => $event, 'messages' => collect([$message])]);
    })->name('events.messages.print');
});

Route::middleware(['auth', 'verified', 'can:log-contacts'])->group(function () {
    Route::get('/events/{event}/messages', MessageTrafficIndex::class)->name('events.messages.index');
    Route::get('/events/{event}/messages/create', MessageForm::class)->name('events.messages.create');
    Route::get('/events/{event}/messages/{message}/edit', MessageForm::class)->name('events.messages.edit');
});

// W1AW Bulletin — accessible to all authenticated users (schedule viewing pre-event)
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/w1aw-bulletin', W1awBulletinForm::class)->name('events.w1aw-bulletin');
});

// Shift Schedule
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/schedule', ScheduleTimeline::class)->name('schedule.index');
    Route::get('/schedule/my-shifts', MyShifts::class)->name('schedule.my-shifts');
});

Route::middleware(['auth', 'verified', 'can:manage-shifts'])->group(function () {
    Route::get('/schedule/manage', ManageSchedule::class)->name('schedule.manage');
});

// Site Safety
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/site-safety', SiteSafetyChecklist::class)->name('site-safety.index');
});

Route::middleware(['auth', 'verified', 'can:manage-shifts'])->group(function () {
    Route::get('/site-safety/manage', ManageSafetyChecklist::class)->name('site-safety.manage');
});

// Equipment Reports - requires manage-event-equipment permission
Route::middleware(['auth', 'verified', 'can:manage-event-equipment'])->prefix('events/{event}/equipment/reports')->name('events.equipment.reports.')->group(function () {
    Route::get('/commitment-summary', [EquipmentReportController::class, 'commitmentSummary'])->name('commitment-summary');
    Route::get('/delivery-checklist', [EquipmentReportController::class, 'deliveryChecklist'])->name('delivery-checklist');
    Route::get('/station-inventory-pdf', [EquipmentReportController::class, 'stationInventoryPdf'])->name('station-inventory-pdf');
    Route::get('/station-inventory-csv', [EquipmentReportController::class, 'stationInventoryCsv'])->name('station-inventory-csv');
    Route::get('/owner-contacts-pdf', [EquipmentReportController::class, 'ownerContactListPdf'])->name('owner-contacts-pdf');
    Route::get('/owner-contacts-csv', [EquipmentReportController::class, 'ownerContactListCsv'])->name('owner-contacts-csv');
    Route::get('/return-checklist', [EquipmentReportController::class, 'returnChecklist'])->name('return-checklist');
    Route::get('/incident-report-pdf', [EquipmentReportController::class, 'incidentReportPdf'])->name('incident-report-pdf');
    Route::get('/incident-report-csv', [EquipmentReportController::class, 'incidentReportCsv'])->name('incident-report-csv');
    Route::get('/historical-record', [EquipmentReportController::class, 'historicalRecord'])->name('historical-record');
});

Route::middleware(['auth', 'verified', 'can:manage-users'])->group(function () {
    Route::get('/users', UserManagement::class)->name('users.index');
});

Route::middleware(['auth', 'verified', 'can:manage-settings'])->group(function () {
    Route::get('/settings', function () {
        return view('settings.index');
    })->name('settings.index');
});

Route::middleware(['auth', 'verified', 'can:view-reports'])->group(function () {
    Route::get('/reports', ReportsIndex::class)->name('reports.index');
    Route::get('/reports/cabrillo', [ReportController::class, 'cabrillo'])->name('reports.cabrillo');
    Route::get('/reports/submission-sheet', [ReportController::class, 'submissionSheet'])->name('reports.submission-sheet');
});

// Administration
Route::middleware(['auth', 'verified', 'can:view-security-logs'])->prefix('admin')->group(function () {
    Route::get('/audit-logs', AuditLogViewer::class)->name('admin.audit-logs');
});

Route::middleware(['auth', 'verified', 'can:import-contacts'])->prefix('admin')->group(function () {
    Route::get('/import/adif', AdifImport::class)->name('admin.import-adif');
    Route::get('/external-loggers', ExternalLoggerManagement::class)->name('admin.external-loggers');
});

// Developer Tools (only available when DEVELOPER_MODE=true in .env)
Route::middleware(['auth', 'verified', 'can:manage-settings'])->prefix('admin')->group(function () {
    Route::get('/developer', DeveloperTools::class)->name('admin.developer');
});

// Weather Dashboard
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/weather', WeatherDashboard::class)->name('weather.index');
});
