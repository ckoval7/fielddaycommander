<?php

namespace App\Providers;

use App\Models\Contact;
use App\Models\EquipmentEvent;
use App\Models\GuestbookEntry;
use App\Models\Image;
use App\Models\Message;
use App\Models\OperatingSession;
use App\Models\W1awBulletin;
use App\Observers\ContactObserver;
use App\Observers\EquipmentEventObserver;
use App\Observers\GuestbookEntryObserver;
use App\Observers\ImageObserver;
use App\Observers\MessageObserver;
use App\Observers\OperatingSessionObserver;
use App\Observers\W1awBulletinObserver;
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
        // Register EventContextService as a scoped binding (extends ActiveEventService)
        // Scoped ensures mutable state resets between Octane requests while sharing within a request
        $this->app->scoped(\App\Services\EventContextService::class);
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
        Gate::policy(\App\Models\Message::class, \App\Policies\MessagePolicy::class);
        Gate::policy(\App\Models\W1awBulletin::class, \App\Policies\W1awBulletinPolicy::class);

        // Register model observers
        Contact::observe(ContactObserver::class);
        EquipmentEvent::observe(EquipmentEventObserver::class);
        GuestbookEntry::observe(GuestbookEntryObserver::class);
        Image::observe(ImageObserver::class);
        Message::observe(MessageObserver::class);
        OperatingSession::observe(OperatingSessionObserver::class);
        W1awBulletin::observe(W1awBulletinObserver::class);
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
