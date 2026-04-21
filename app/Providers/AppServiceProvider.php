<?php

namespace App\Providers;

use App\Models\Contact;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\GuestbookEntry;
use App\Models\Image;
use App\Models\Message;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\W1awBulletin;
use App\Observers\ContactObserver;
use App\Observers\EquipmentEventObserver;
use App\Observers\EventObserver;
use App\Observers\GuestbookEntryObserver;
use App\Observers\ImageObserver;
use App\Observers\MessageObserver;
use App\Observers\OperatingSessionObserver;
use App\Observers\StationObserver;
use App\Observers\W1awBulletinObserver;
use App\Policies\GuestbookEntryPolicy;
use App\Policies\ImagePolicy;
use App\Policies\MessagePolicy;
use App\Policies\W1awBulletinPolicy;
use App\Services\ActiveEventService;
use App\Services\EventContextService;
use App\View\Components\Icon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
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
        $this->app->scoped(EventContextService::class);
        $this->app->alias(EventContextService::class, ActiveEventService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Define rate limiters
        $this->configureRateLimiters();

        // Override Mary UI's <x-icon> component with a prefix-aware version
        // so names like `phosphor-house` resolve via Blade Icons' prefix
        // routing instead of being force-prefixed with `heroicon-`. Names
        // without a registered prefix (e.g. `o-bolt`) still get the
        // `heroicon-` prefix applied for backward compatibility. The
        // `mary-icon` alias is used by Mary's internal components (Button,
        // Alert, etc.), so override it too via `booted()` to ensure our
        // registration runs after Mary's service provider.
        Blade::component('icon', Icon::class);
        $this->app->booted(function (): void {
            Blade::component('mary-icon', Icon::class);
        });

        // Register policies
        Gate::policy(Image::class, ImagePolicy::class);
        Gate::policy(GuestbookEntry::class, GuestbookEntryPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);
        Gate::policy(W1awBulletin::class, W1awBulletinPolicy::class);

        // Register model observers
        Contact::observe(ContactObserver::class);
        EquipmentEvent::observe(EquipmentEventObserver::class);
        Event::observe(EventObserver::class);
        GuestbookEntry::observe(GuestbookEntryObserver::class);
        Image::observe(ImageObserver::class);
        Message::observe(MessageObserver::class);
        OperatingSession::observe(OperatingSessionObserver::class);
        Station::observe(StationObserver::class);
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
