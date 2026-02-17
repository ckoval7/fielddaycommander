<?php

namespace App\Providers;

use App\Models\Contact;
use App\Models\EquipmentEvent;
use App\Models\GuestbookEntry;
use App\Models\Image;
use App\Models\OperatingSession;
use App\Observers\ContactObserver;
use App\Observers\EquipmentEventObserver;
use App\Observers\GuestbookEntryObserver;
use App\Observers\ImageObserver;
use App\Observers\OperatingSessionObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register EventContextService as a singleton (extends ActiveEventService)
        $this->app->singleton(\App\Services\EventContextService::class);
        $this->app->alias(\App\Services\EventContextService::class, \App\Services\ActiveEventService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Define rate limiters
        $this->configureRateLimiters();

        // Register policies
        Gate::policy(\App\Models\Image::class, \App\Policies\ImagePolicy::class);
        Gate::policy(\App\Models\GuestbookEntry::class, \App\Policies\GuestbookEntryPolicy::class);

        // Register model observers
        Contact::observe(ContactObserver::class);
        EquipmentEvent::observe(EquipmentEventObserver::class);
        GuestbookEntry::observe(GuestbookEntryObserver::class);
        Image::observe(ImageObserver::class);
        OperatingSession::observe(OperatingSessionObserver::class);
    }

    /**
     * Configure the application's rate limiters.
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('guestbook', function (Request $request) {
            return Limit::perHour(5)->by($request->ip());
        });
    }
}
