<?php

namespace App\Services;

use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing dashboard CRUD operations.
 *
 * Handles dashboard creation, updates, deletion, duplication, and config
 * validation. Enforces business constraints such as maximum dashboards per
 * user and maximum widgets per dashboard.
 */
class DashboardService
{
    /**
     * Create a new dashboard for a user.
     *
     * Applies the default 'user' layout when no config is provided.
     * Enforces the max dashboards per user constraint.
     *
     * @param  User  $user  The dashboard owner
     * @param  string  $title  Dashboard title
     * @param  string|null  $description  Optional description
     * @param  array<int, array<string, mixed>>|null  $config  Widget configuration array
     *
     * @throws \OverflowException When the user has reached the max dashboards limit
     * @throws \InvalidArgumentException When the widget config is invalid
     */
    public function createDashboard(
        User $user,
        string $title,
        ?string $description = null,
        ?array $config = null,
    ): Dashboard {
        $this->enforceMaxDashboards($user);

        $config ??= $this->applyDefaultLayout('user');

        if (! $this->validateConfig($config)) {
            throw new \InvalidArgumentException('Invalid widget configuration structure.');
        }

        $this->enforceMaxWidgets($config);

        $isDefault = $user->dashboards()->count() === 0;

        return Dashboard::create([
            'user_id' => $user->id,
            'title' => $title,
            'description' => $description,
            'config' => $config,
            'is_default' => $isDefault,
            'layout_type' => 'grid',
        ]);
    }

    /**
     * Update a dashboard's title, description, or widget config.
     *
     * Only updates the fields present in $data. Validates config structure
     * before saving when config is included in the update.
     *
     * @param  Dashboard  $dashboard  The dashboard to update
     * @param  array{title?: string, description?: string|null, config?: array<int, array<string, mixed>>}  $data  Fields to update
     *
     * @throws \InvalidArgumentException When the widget config is invalid
     */
    public function updateDashboard(Dashboard $dashboard, array $data): Dashboard
    {
        if (isset($data['config'])) {
            if (! $this->validateConfig($data['config'])) {
                throw new \InvalidArgumentException('Invalid widget configuration structure.');
            }

            $this->enforceMaxWidgets($data['config']);
        }

        $dashboard->update($data);

        return $dashboard->refresh();
    }

    /**
     * Delete a dashboard.
     *
     * Prevents deletion when this is the user's only dashboard or when it is
     * currently set as the default (another must be set as default first).
     *
     * @param  Dashboard  $dashboard  The dashboard to delete
     *
     * @throws \LogicException When deletion would leave the user with no dashboards
     * @throws \LogicException When trying to delete the default dashboard
     */
    public function deleteDashboard(Dashboard $dashboard): bool
    {
        $userDashboardCount = Dashboard::query()
            ->forUser($dashboard->user)
            ->count();

        if ($userDashboardCount <= 1) {
            throw new \LogicException('Cannot delete the only dashboard. Create another dashboard first.');
        }

        if ($dashboard->is_default) {
            throw new \LogicException('Cannot delete the default dashboard. Set another dashboard as default first.');
        }

        return (bool) $dashboard->delete();
    }

    /**
     * Duplicate a dashboard with a new title.
     *
     * Copies all widget configuration from the source dashboard.
     * The duplicated dashboard is never set as the default.
     *
     * @param  Dashboard  $dashboard  The dashboard to duplicate
     * @param  string  $newTitle  Title for the duplicated dashboard
     *
     * @throws \OverflowException When the user has reached the max dashboards limit
     */
    public function duplicateDashboard(Dashboard $dashboard, string $newTitle): Dashboard
    {
        $this->enforceMaxDashboards($dashboard->user);

        return Dashboard::create([
            'user_id' => $dashboard->user_id,
            'title' => $newTitle,
            'description' => $dashboard->description,
            'config' => $dashboard->config,
            'is_default' => false,
            'layout_type' => $dashboard->layout_type,
        ]);
    }

    /**
     * Get the user's default dashboard, creating one if necessary.
     *
     * When the user has no dashboards, creates one with the default 'user'
     * layout. When dashboards exist but none is marked as default, the first
     * dashboard is promoted to default.
     *
     * @param  User  $user  The user whose default dashboard to retrieve
     */
    public function getDefaultDashboard(User $user): Dashboard
    {
        $default = Dashboard::query()
            ->forUser($user)
            ->default()
            ->first();

        if ($default) {
            return $default;
        }

        $firstDashboard = Dashboard::query()
            ->forUser($user)
            ->oldest()
            ->first();

        if ($firstDashboard) {
            $firstDashboard->update(['is_default' => true]);

            return $firstDashboard->refresh();
        }

        return Dashboard::create([
            'user_id' => $user->id,
            'title' => 'My Dashboard',
            'config' => $this->applyDefaultLayout('user'),
            'is_default' => true,
            'layout_type' => 'grid',
        ]);
    }

    /**
     * Set a dashboard as the user's default.
     *
     * Unsets the previous default dashboard for this user within a
     * transaction to maintain data consistency.
     *
     * @param  Dashboard  $dashboard  The dashboard to set as default
     */
    public function setAsDefault(Dashboard $dashboard): void
    {
        DB::transaction(function () use ($dashboard) {
            Dashboard::query()
                ->forUser($dashboard->user)
                ->default()
                ->where('id', '!=', $dashboard->id)
                ->update(['is_default' => false]);

            $dashboard->update(['is_default' => true]);
        });
    }

    /**
     * Validate a widget configuration array structure.
     *
     * Each widget must contain: id (string), type (valid widget type),
     * config (array), order (integer), and visible (boolean).
     *
     * @param  array<int, array<string, mixed>>  $config  Widget configuration to validate
     */
    public function validateConfig(array $config): bool
    {
        $validTypes = array_keys(config('dashboard.widget_types', []));

        foreach ($config as $widget) {
            if (! is_array($widget)) {
                return false;
            }

            if (! isset($widget['id']) || ! is_string($widget['id'])) {
                return false;
            }

            if (! isset($widget['type']) || ! is_string($widget['type'])) {
                return false;
            }

            if (! in_array($widget['type'], $validTypes, true)) {
                return false;
            }

            if (isset($widget['config']) && ! is_array($widget['config'])) {
                return false;
            }

            if (isset($widget['order']) && ! is_int($widget['order'])) {
                return false;
            }

            if (isset($widget['visible']) && ! is_bool($widget['visible'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the default widget configuration for a layout type.
     *
     * Loads from config('dashboard.default_dashboards.{layoutType}').
     * Falls back to the 'user' layout if the requested type is not found.
     *
     * @param  string  $layoutType  One of 'guest', 'user', or 'tv'
     * @return array<int, array<string, mixed>>
     */
    public function applyDefaultLayout(string $layoutType): array
    {
        $layout = config("dashboard.default_dashboards.{$layoutType}");

        if (! is_array($layout)) {
            Log::warning('Dashboard layout type not found, falling back to user layout.', [
                'requested_type' => $layoutType,
            ]);

            $layout = config('dashboard.default_dashboards.user', []);
        }

        // Return only the widgets array, not the full layout config
        return $layout['widgets'] ?? [];
    }

    /**
     * Enforce the maximum dashboards per user constraint.
     *
     * @throws \OverflowException When the limit is reached
     */
    protected function enforceMaxDashboards(User $user): void
    {
        $max = (int) config('dashboard.max_dashboards_per_user', 10);
        $currentCount = $user->dashboards()->count();

        if ($currentCount >= $max) {
            throw new \OverflowException(
                "Cannot create dashboard: user already has {$currentCount} dashboards (maximum {$max})."
            );
        }
    }

    /**
     * Enforce the maximum widgets per dashboard constraint.
     *
     * @param  array<int, array<string, mixed>>  $config  Widget configuration to check
     *
     * @throws \OverflowException When the widget count exceeds the limit
     */
    protected function enforceMaxWidgets(array $config): void
    {
        $max = (int) config('dashboard.max_widgets_per_dashboard', 20);

        if (count($config) > $max) {
            throw new \OverflowException(
                'Cannot save dashboard: config contains '.count($config)." widgets (maximum {$max})."
            );
        }
    }
}
