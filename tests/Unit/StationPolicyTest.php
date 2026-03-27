<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use App\Policies\StationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Test the StationPolicy authorization logic.
 *
 * Note: These tests use Gate::define() to mock the 'manage-stations' permission
 * because there's currently a mismatch between the database schema (which uses
 * enum('user','admin','locked')) and the AppServiceProvider gates (which check
 * for 'ADMIN', 'STATION_CAPTAIN', 'OPERATOR'). This should be resolved by either:
 * 1. Updating the migration to match the gate values, OR
 * 2. Using Spatie permissions instead of the user_role enum
 */
class StationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private StationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new StationPolicy;
    }

    public function test_users_with_view_stations_permission_can_view_any_stations(): void
    {
        $authorizedUser = User::factory()->create();
        $unauthorizedUser = User::factory()->create();

        // Mock the view-stations gate for testing
        Gate::define('view-stations', fn (User $user) => $user->id === $authorizedUser->id);

        expect($this->policy->viewAny($authorizedUser))->toBeTrue();
        expect($this->policy->viewAny($unauthorizedUser))->toBeFalse();
    }

    public function test_users_with_view_stations_permission_can_view_individual_stations(): void
    {
        $authorizedUser = User::factory()->create();
        $unauthorizedUser = User::factory()->create();
        $station = Station::factory()->create();

        Gate::define('view-stations', fn (User $user) => $user->id === $authorizedUser->id);

        expect($this->policy->view($authorizedUser, $station))->toBeTrue();
        expect($this->policy->view($unauthorizedUser, $station))->toBeFalse();
    }

    public function test_users_with_manage_stations_permission_can_create_stations(): void
    {
        $authorizedUser = User::factory()->create();
        $unauthorizedUser = User::factory()->create();

        Gate::define('manage-stations', fn (User $user) => $user->id === $authorizedUser->id);

        expect($this->policy->create($authorizedUser))->toBeTrue();
        expect($this->policy->create($unauthorizedUser))->toBeFalse();
    }

    public function test_users_with_manage_stations_permission_can_update_stations(): void
    {
        $authorizedUser = User::factory()->create();
        $unauthorizedUser = User::factory()->create();
        $station = Station::factory()->create();

        Gate::define('manage-stations', fn (User $user) => $user->id === $authorizedUser->id);

        expect($this->policy->update($authorizedUser, $station))->toBeTrue();
        expect($this->policy->update($unauthorizedUser, $station))->toBeFalse();
    }

    public function test_cannot_delete_station_with_active_operating_sessions(): void
    {
        $authorizedUser = User::factory()->create();
        $station = Station::factory()->create();

        // Create an active operating session (no end_time)
        OperatingSession::factory()->create([
            'station_id' => $station->id,
            'end_time' => null,
        ]);

        Gate::define('manage-stations', fn () => true);

        expect($this->policy->delete($authorizedUser, $station))->toBeFalse();
    }

    public function test_can_delete_station_without_active_operating_sessions(): void
    {
        $authorizedUser = User::factory()->create();
        $station = Station::factory()->create();

        // Create an ended operating session
        OperatingSession::factory()->create([
            'station_id' => $station->id,
            'end_time' => now(),
        ]);

        Gate::define('manage-stations', fn () => true);

        expect($this->policy->delete($authorizedUser, $station))->toBeTrue();
    }

    public function test_can_delete_station_with_no_operating_sessions(): void
    {
        $authorizedUser = User::factory()->create();
        $station = Station::factory()->create();

        Gate::define('manage-stations', fn () => true);

        expect($this->policy->delete($authorizedUser, $station))->toBeTrue();
    }

    public function test_users_without_permission_cannot_delete_stations(): void
    {
        $unauthorizedUser = User::factory()->create();
        $station = Station::factory()->create();

        Gate::define('manage-stations', fn () => false);

        expect($this->policy->delete($unauthorizedUser, $station))->toBeFalse();
    }

    public function test_users_with_manage_stations_permission_can_restore_stations(): void
    {
        $authorizedUser = User::factory()->create();
        $unauthorizedUser = User::factory()->create();
        $station = Station::factory()->create();
        $station->delete(); // Soft delete

        Gate::define('manage-stations', fn (User $user) => $user->id === $authorizedUser->id);

        expect($this->policy->restore($authorizedUser, $station))->toBeTrue();
        expect($this->policy->restore($unauthorizedUser, $station))->toBeFalse();
    }

    public function test_cannot_force_delete_station_with_contacts(): void
    {
        $authorizedUser = User::factory()->create();
        $station = Station::factory()->create();
        $session = OperatingSession::factory()->create([
            'station_id' => $station->id,
        ]);

        // Create a contact through the operating session
        Contact::factory()->create([
            'operating_session_id' => $session->id,
        ]);

        Gate::define('manage-stations', fn () => true);

        expect($this->policy->forceDelete($authorizedUser, $station))->toBeFalse();
    }

    public function test_can_force_delete_station_without_contacts(): void
    {
        $authorizedUser = User::factory()->create();
        $station = Station::factory()->create();

        Gate::define('manage-stations', fn () => true);

        expect($this->policy->forceDelete($authorizedUser, $station))->toBeTrue();
    }

    public function test_users_without_permission_cannot_force_delete_stations(): void
    {
        $unauthorizedUser = User::factory()->create();
        $station = Station::factory()->create();

        Gate::define('manage-stations', fn () => false);

        expect($this->policy->forceDelete($unauthorizedUser, $station))->toBeFalse();
    }
}
