<?php

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

// Dashboard
Route::get('/', function () {
    return view('dashboard');
})->name('dashboard');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard.alt');

// Profile Management - TODO: Create ProfileController or move to Livewire component
// Route::middleware('auth')->group(function () {
//     Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
//     Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
//     Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
// });

// Contact Logging
Route::middleware(['auth', 'can:log-contacts'])->group(function () {
    Route::get('/contacts/create', function () {
        return view('contacts.create');
    })->name('contacts.create');
});

Route::get('/contacts', function () {
    return view('contacts.index');
})->name('contacts.index');

// Event Management
Route::middleware('auth')->group(function () {
    Route::get('/scoring', function () {
        return view('scoring.index');
    })->name('scoring.index');

    Route::get('/gallery', function () {
        return view('gallery.index');
    })->name('gallery.index');

    Route::get('/guestbook', function () {
        return view('guestbook.index');
    })->name('guestbook.index');
});

Route::middleware(['auth', 'can:manage-bonuses'])->group(function () {
    Route::get('/bonuses', function () {
        return view('bonuses.index');
    })->name('bonuses.index');
});

Route::middleware(['auth', 'can:manage-stations'])->group(function () {
    Route::get('/stations', function () {
        return view('stations.index');
    })->name('stations.index');
});

Route::middleware(['auth', 'can:manage-equipment'])->group(function () {
    Route::get('/equipment', function () {
        return view('equipment.index');
    })->name('equipment.index');
});

// Administration
Route::middleware(['auth', 'can:view-events'])->group(function () {
    Route::get('/events', \App\Livewire\Events\EventsList::class)->name('events.index');
    Route::get('/events/{event}', \App\Livewire\Events\EventDashboard::class)->name('events.show');
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

Route::middleware(['auth', 'can:manage-users'])->group(function () {
    Route::get('/users', function () {
        return view('users.index');
    })->name('users.index');
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
