<?php

use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\SetupController;
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

// Dashboard
Route::get('/', function () {
    return view('dashboard');
})->name('dashboard');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard.alt');

// Profile Management
Route::middleware('auth')->group(function () {
    Route::get('/profile', \App\Livewire\Profile\UserProfile::class)->name('profile');
});

// Contact Logging
Route::middleware(['auth', 'can:log-contacts'])->group(function () {
    Route::get('/contacts/create', function () {
        return view('contacts.create');
    })->name('contacts.create');
    Route::get('/logging', \App\Livewire\Logging\StationSelect::class)->name('logging.station-select');
    Route::get('/logging/session/{operatingSession}', \App\Livewire\Logging\LoggingInterface::class)->name('logging.session');
});

Route::get('/contacts', function () {
    return view('contacts.index');
})->name('contacts.index');

// Event Management
Route::middleware('auth')->group(function () {
    Route::get('/scoring', function () {
        return view('scoring.index');
    })->name('scoring.index');
});

// Public Guestbook
Route::get('/guestbook', function () {
    return view('guestbook.index');
})->name('guestbook.index');

// Gallery
Route::get('/gallery', \App\Livewire\Gallery\GalleryIndex::class)->name('gallery.index');
Route::get('/gallery/{eventConfiguration}', \App\Livewire\Gallery\GalleryShow::class)->name('gallery.show');
Route::get('/gallery/{eventConfiguration}/upload', \App\Livewire\Gallery\GalleryUpload::class)
    ->middleware('auth')
    ->name('gallery.upload');
Route::get('/gallery/thumb/{image}', [\App\Http\Controllers\GalleryController::class, 'thumbnail'])->name('gallery.thumb');
Route::get('/gallery/image/{image}', [\App\Http\Controllers\GalleryController::class, 'image'])->name('gallery.image');

Route::middleware(['auth', 'can:manage-bonuses'])->group(function () {
    Route::get('/bonuses', function () {
        return view('bonuses.index');
    })->name('bonuses.index');
});

Route::middleware(['auth', 'can:view-stations'])->group(function () {
    Route::get('/stations', \App\Livewire\Stations\StationsList::class)->name('stations.index');
});

Route::middleware(['auth', 'can:manage-stations'])->group(function () {
    Route::get('/stations/create', \App\Livewire\Stations\StationForm::class)->name('stations.create');
    Route::get('/stations/{station}/edit', \App\Livewire\Stations\StationForm::class)->name('stations.edit');
});

Route::middleware(['auth', 'can:manage-own-equipment'])->group(function () {
    Route::get('/equipment', \App\Livewire\Equipment\EquipmentList::class)->name('equipment.index');
    Route::get('/equipment/create', \App\Livewire\Equipment\EquipmentForm::class)->name('equipment.create');
    Route::get('/equipment/{equipment}/edit', \App\Livewire\Equipment\EquipmentForm::class)->name('equipment.edit');
    Route::get('/equipment/events', \App\Livewire\Equipment\EventEquipment::class)->name('equipment.events');
});

// Administration
Route::middleware(['auth', 'can:view-events'])->group(function () {
    Route::get('/events', \App\Livewire\Events\EventsList::class)->name('events.index');
});

Route::middleware(['auth', 'can:create-events'])->group(function () {
    Route::get('/events/create', \App\Livewire\Events\EventForm::class)->name('events.create');
});

Route::middleware(['auth', 'can:edit-events'])->group(function () {
    Route::get('/events/{eventId}/edit', \App\Livewire\Events\EventForm::class)->name('events.edit');
});

Route::middleware(['auth', 'can:create-events'])->group(function () {
    Route::get('/events/{eventId}/clone', \App\Livewire\Events\EventForm::class)->name('events.clone');
});

Route::middleware(['auth', 'can:view-events'])->group(function () {
    Route::get('/events/{event}', \App\Livewire\Events\EventDashboard::class)->name('events.show');
});

// Equipment Dashboard - requires manage-event-equipment OR view-all-equipment (checked in component)
Route::middleware('auth')->group(function () {
    Route::get('/events/{event}/equipment', \App\Livewire\Equipment\EventEquipmentDashboard::class)->name('events.equipment.dashboard');
});

// Guestbook Management - requires manage-guestbook permission for editing/verifying entries
Route::middleware(['auth', 'can:manage-guestbook'])->group(function () {
    Route::get('/events/{event}/guestbook', \App\Livewire\Guestbook\GuestbookManager::class)->name('events.guestbook');
});

// Equipment Reports - requires manage-event-equipment permission
Route::middleware(['auth', 'can:manage-event-equipment'])->prefix('events/{event}/equipment/reports')->name('events.equipment.reports.')->group(function () {
    Route::get('/commitment-summary', [\App\Http\Controllers\Equipment\EquipmentReportController::class, 'commitmentSummary'])->name('commitment-summary');
    Route::get('/delivery-checklist', [\App\Http\Controllers\Equipment\EquipmentReportController::class, 'deliveryChecklist'])->name('delivery-checklist');
    Route::get('/station-inventory-pdf', [\App\Http\Controllers\Equipment\EquipmentReportController::class, 'stationInventoryPdf'])->name('station-inventory-pdf');
    Route::get('/station-inventory-csv', [\App\Http\Controllers\Equipment\EquipmentReportController::class, 'stationInventoryCsv'])->name('station-inventory-csv');
    Route::get('/owner-contacts-pdf', [\App\Http\Controllers\Equipment\EquipmentReportController::class, 'ownerContactListPdf'])->name('owner-contacts-pdf');
    Route::get('/owner-contacts-csv', [\App\Http\Controllers\Equipment\EquipmentReportController::class, 'ownerContactListCsv'])->name('owner-contacts-csv');
    Route::get('/return-checklist', [\App\Http\Controllers\Equipment\EquipmentReportController::class, 'returnChecklist'])->name('return-checklist');
    Route::get('/incident-report-pdf', [\App\Http\Controllers\Equipment\EquipmentReportController::class, 'incidentReportPdf'])->name('incident-report-pdf');
    Route::get('/incident-report-csv', [\App\Http\Controllers\Equipment\EquipmentReportController::class, 'incidentReportCsv'])->name('incident-report-csv');
    Route::get('/historical-record', [\App\Http\Controllers\Equipment\EquipmentReportController::class, 'historicalRecord'])->name('historical-record');
});

Route::middleware(['auth', 'can:manage-users'])->group(function () {
    Route::get('/users', \App\Livewire\Users\UserManagement::class)->name('users.index');
});

Route::middleware(['auth', 'can:manage-settings'])->group(function () {
    Route::get('/settings', function () {
        return view('settings.index');
    })->name('settings.index');
});

Route::middleware(['auth', 'can:view-reports'])->group(function () {
    Route::get('/reports', function () {
        return view('reports.index');
    })->name('reports.index');
});

// Administration
Route::middleware(['auth', 'can:view-security-logs'])->prefix('admin')->group(function () {
    Route::get('/audit-logs', \App\Livewire\Admin\AuditLogViewer::class)->name('admin.audit-logs');
});

// Developer Tools (only available when DEVELOPER_MODE=true in .env)
Route::middleware(['auth', 'can:manage-settings'])->prefix('admin')->group(function () {
    Route::get('/developer', \App\Livewire\Admin\DeveloperTools::class)->name('admin.developer');
});
