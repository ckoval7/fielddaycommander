<?php

// SPDX-FileCopyrightText: Taylor Otwell
// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later AND MIT

namespace App\Providers;

use App\Http\Middleware\EnforceStationMode;
use App\Livewire\Hooks\ReportValidationFailures;
use App\Models\Contact;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\GuestbookEntry;
use App\Models\Image;
use App\Models\Message;
use App\Models\OperatingSession;
use App\Models\ShiftAssignment;
use App\Models\Station;
use App\Models\User;
use App\Models\W1awBulletin;
use App\Observers\ContactObserver;
use App\Observers\EquipmentEventObserver;
use App\Observers\EventObserver;
use App\Observers\GuestbookEntryObserver;
use App\Observers\ImageObserver;
use App\Observers\MessageObserver;
use App\Observers\OperatingSessionObserver;
use App\Observers\ShiftAssignmentObserver;
use App\Observers\StationObserver;
use App\Observers\W1awBulletinObserver;
use App\Policies\GuestbookEntryPolicy;
use App\Policies\ImagePolicy;
use App\Policies\MessagePolicy;
use App\Policies\W1awBulletinPolicy;
use App\Scoring\RuleSetFactory;
use App\Services\ActiveEventService;
use App\Services\EventContextService;
use App\Support\StationMode;
use App\View\Components\Breadcrumbs;
use App\View\Components\Button;
use App\View\Components\Checkbox;
use App\View\Components\Choices;
use App\View\Components\ChoicesOffline;
use App\View\Components\DateTime;
use App\View\Components\File;
use App\View\Components\Header;
use App\View\Components\Icon;
use App\View\Components\Input;
use App\View\Components\Main;
use App\View\Components\MenuItem;
use App\View\Components\MenuSeparator;
use App\View\Components\Modal;
use App\View\Components\Pagination;
use App\View\Components\Range;
use App\View\Components\Select;
use App\View\Components\Tab;
use App\View\Components\Table;
use App\View\Components\Tabs;
use App\View\Components\Textarea;
use App\View\Components\Toggle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Mary components replaced by subclasses, keyed by their unprefixed alias.
     *
     * @var array<string, class-string>
     */
    public const ACCESSIBLE_MARY_COMPONENTS = [
        'breadcrumbs' => Breadcrumbs::class,
        'button' => Button::class,
        'checkbox' => Checkbox::class,
        'choices' => Choices::class,
        'choices-offline' => ChoicesOffline::class,
        'datetime' => DateTime::class,
        'file' => File::class,
        'header' => Header::class,
        'input' => Input::class,
        'main' => Main::class,
        'menu-item' => MenuItem::class,
        'menu-separator' => MenuSeparator::class,
        'modal' => Modal::class,
        'pagination' => Pagination::class,
        'range' => Range::class,
        'select' => Select::class,
        'tab' => Tab::class,
        'table' => Table::class,
        'tabs' => Tabs::class,
        'textarea' => Textarea::class,
        'toggle' => Toggle::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register EventContextService as a scoped binding (extends ActiveEventService)
        // Scoped ensures mutable state resets between Octane requests while sharing within a request
        $this->app->scoped(EventContextService::class);
        $this->app->alias(EventContextService::class, ActiveEventService::class);

        $this->app->singleton(RuleSetFactory::class);

        // Flag Livewire actions that fail validation so the frontend can show the
        // floating error summary (resources/js/components/form-error-summary.js).
        // Must be registered before Livewire boots its component hooks.
        Livewire::componentHook(ReportValidationFailures::class);
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

        // Mary components patched for screen readers and keyboards, plus
        // null-safe getters for <x-choices-offline>; see each class docblock.
        // Mary's own templates use the `mary-` aliases, so cover those too.
        $this->app->booted(function (): void {
            $prefix = config('mary.prefix');

            foreach (self::ACCESSIBLE_MARY_COMPONENTS as $alias => $component) {
                Blade::component($prefix.$alias, $component);
            }

            foreach (['button', 'header', 'input', 'menu-item', 'modal', 'pagination'] as $alias) {
                Blade::component('mary-'.$alias, self::ACCESSIBLE_MARY_COMPONENTS[$alias]);
            }
        });

        $this->configureStationMode();

        // The settings page hosts system settings and the role manager, so
        // either permission opens it. Each tab checks its own permission.
        Gate::define('access-settings', fn (User $user): bool => $user->can('manage-settings') || $user->can('manage-roles'));

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
        ShiftAssignment::observe(ShiftAssignmentObserver::class);
        Station::observe(StationObserver::class);
        W1awBulletin::observe(W1awBulletinObserver::class);
    }

    /**
     * Lock station-mode sessions down beyond the route allowlist.
     */
    protected function configureStationMode(): void
    {
        // Re-check station mode against the page a component came from on every
        // Livewire update, so a snapshot from a page station mode blocks can't be
        // replayed through the update endpoint.
        Livewire::addPersistentMiddleware([EnforceStationMode::class]);

        // Deny owner-based policy writes (deleting your own photo, editing your
        // own message) that never reach the permission cap on User.
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->isInStationMode() || StationMode::permitsAbility($ability)) {
                return null;
            }

            return false;
        });
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
