<?php

namespace App\Providers;

use App\Models\EquipmentEvent;
use App\Models\User;
use App\Observers\EquipmentEventObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model observers
        EquipmentEvent::observe(EquipmentEventObserver::class);

        // Define authorization gates based on user roles
        Gate::define('log-contacts', function (User $user) {
            return in_array($user->user_role, ['OPERATOR', 'ADMIN']);
        });

        Gate::define('manage-bonuses', function (User $user) {
            return $user->user_role === 'ADMIN';
        });

        Gate::define('manage-stations', function (User $user) {
            return in_array($user->user_role, ['ADMIN', 'STATION_CAPTAIN']);
        });

        Gate::define('manage-equipment', function (User $user) {
            return in_array($user->user_role, ['ADMIN', 'STATION_CAPTAIN']);
        });

        Gate::define('manage-events', function (User $user) {
            return $user->user_role === 'ADMIN';
        });

        Gate::define('manage-users', function (User $user) {
            return $user->user_role === 'ADMIN';
        });

        Gate::define('manage-settings', function (User $user) {
            return $user->user_role === 'ADMIN';
        });

        Gate::define('view-reports', function (User $user) {
            return in_array($user->user_role, ['ADMIN', 'STATION_CAPTAIN']);
        });
    }
}
