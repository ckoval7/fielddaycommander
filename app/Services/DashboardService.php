<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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
     * Widest a widget may be, in grid columns. The dashboard grid is at most
     * three columns wide (lg:grid-cols-3 in dashboard-editor.blade.php).
     */
    public const MAX_COL_SPAN = 3;

    /**
     * Shortest widget height, in grid rows ("Short").
     */
    public const MIN_ROW_SPAN = 2;

    /**
     * Tallest widget height, in grid rows ("Tall").
     */
    public const MAX_ROW_SPAN = 6;

    /**
     * Longest value kept for free-text widget settings.
     */
    protected const MAX_TEXT_SETTING_LENGTH = 255;

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
            'config' => $this->normalizeWidgets($config),
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

            $data['config'] = $this->normalizeWidgets($data['config']);
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
     * Copies all widget configuration from the source dashboard into the
     * owner's account (the source's owner when none is given). Callers must
     * only pass a source dashboard the owner is allowed to read.
     * The duplicated dashboard is never set as the default.
     *
     * @param  Dashboard  $dashboard  The dashboard to duplicate
     * @param  string  $newTitle  Title for the duplicated dashboard
     * @param  User|null  $owner  Account that receives the copy
     *
     * @throws \OverflowException When the owner has reached the max dashboards limit
     */
    public function duplicateDashboard(Dashboard $dashboard, string $newTitle, ?User $owner = null): Dashboard
    {
        $owner ??= $dashboard->user;

        $this->enforceMaxDashboards($owner);

        return Dashboard::create([
            'user_id' => $owner->id,
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
            'title' => config('dashboard.default_dashboards.user.title', 'My Dashboard'),
            'description' => config('dashboard.default_dashboards.user.description'),
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
            if (! $this->isValidWidgetEntry($widget, $validTypes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a single widget entry within a dashboard config.
     *
     * @param  mixed  $widget  The widget entry to validate
     * @param  array<int, string>  $validTypes  Allowed widget type strings
     */
    protected function isValidWidgetEntry(mixed $widget, array $validTypes): bool
    {
        if (! is_array($widget)
            || ! isset($widget['id']) || ! is_string($widget['id'])
            || ! isset($widget['type']) || ! is_string($widget['type']) || ! in_array($widget['type'], $validTypes, true)
        ) {
            return false;
        }

        // Optional fields must have correct types when present
        $optionalTypeChecks = [
            'config' => fn ($v) => is_array($v),
            'order' => fn ($v) => is_int($v),
            'visible' => fn ($v) => is_bool($v),
            'col_span' => fn ($v) => is_int($v),
            'row_span' => fn ($v) => is_int($v),
        ];

        foreach ($optionalTypeChecks as $field => $check) {
            if (isset($widget[$field]) && ! $check($widget[$field])) {
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
     * Maximum widgets allowed on one dashboard.
     */
    public function maxWidgetsPerDashboard(): int
    {
        return (int) config('dashboard.limits.max_widgets_per_dashboard', 20);
    }

    /**
     * Clamp a column span to the supported range.
     */
    public function clampColSpan(int $colSpan): int
    {
        return max(1, min(self::MAX_COL_SPAN, $colSpan));
    }

    /**
     * Clamp a row span to the supported range (Short through Tall).
     */
    public function clampRowSpan(int $rowSpan): int
    {
        return max(self::MIN_ROW_SPAN, min(self::MAX_ROW_SPAN, $rowSpan));
    }

    /**
     * Bring stored widget entries into canonical shape before saving.
     *
     * Settings are cleaned against the widget type's schema and spans are
     * clamped, so values the client sent outside the configurator's choices
     * (e.g. a 100,000-row list) never reach the widgets.
     *
     * @param  array<int, array<string, mixed>>  $widgets  Entries already passed validateConfig()
     * @return array<int, array<string, mixed>>
     */
    public function normalizeWidgets(array $widgets): array
    {
        return array_values(array_map(function (array $widget): array {
            $widget['config'] = $this->sanitizeWidgetConfig($widget['type'], $widget['config'] ?? []);

            if (isset($widget['col_span'])) {
                $widget['col_span'] = $this->clampColSpan((int) $widget['col_span']);
            }

            if (isset($widget['row_span'])) {
                $widget['row_span'] = $this->clampRowSpan((int) $widget['row_span']);
            }

            return $widget;
        }, $widgets));
    }

    /**
     * Keep only the settings a widget type's schema defines, with valid values.
     *
     * Unknown keys are dropped; invalid values fall back to the schema default,
     * or are dropped when the field has none so the widget's own default applies.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function sanitizeWidgetConfig(string $type, array $config): array
    {
        $schema = config("dashboard.widget_types.{$type}.config_schema", []);
        $clean = [];

        foreach ($schema as $field => $definition) {
            if (! array_key_exists($field, $config)) {
                continue;
            }

            $value = $this->sanitizeSettingValue($config[$field], $definition);

            if ($value === null && array_key_exists('default', $definition)) {
                $value = $definition['default'];
            }

            if ($value !== null) {
                $clean[$field] = $value;
            }
        }

        return $clean;
    }

    /**
     * Validate one setting value against its schema definition.
     *
     * @param  array<string, mixed>  $definition
     */
    protected function sanitizeSettingValue(mixed $value, array $definition): mixed
    {
        return match ($definition['type'] ?? 'text') {
            'select' => is_scalar($value)
                && in_array((string) $value, array_map('strval', array_keys($definition['options'] ?? [])), true)
                    ? (string) $value
                    : null,
            'toggle', 'checkbox' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'number' => is_numeric($value)
                ? max($definition['min'] ?? PHP_INT_MIN, min($definition['max'] ?? PHP_INT_MAX, $value + 0))
                : null,
            default => is_scalar($value)
                ? mb_substr((string) $value, 0, self::MAX_TEXT_SETTING_LENGTH)
                : null,
        };
    }

    /**
     * Enforce the maximum dashboards per user constraint.
     *
     * @throws \OverflowException When the limit is reached
     */
    protected function enforceMaxDashboards(User $user): void
    {
        $max = (int) config('dashboard.limits.max_dashboards_per_user', 10);
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
        $max = $this->maxWidgetsPerDashboard();

        if (count($config) > $max) {
            throw new \OverflowException(
                'Cannot save dashboard: config contains '.count($config)." widgets (maximum {$max})."
            );
        }
    }
}
