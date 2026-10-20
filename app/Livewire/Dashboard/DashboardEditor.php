<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Dashboard;

use App\Models\Dashboard;
use App\Services\DashboardService;
use App\Services\EventContextService;
use App\Support\StationMode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Dashboard editing component with drag-and-drop widget management.
 *
 * Integrates with the dashboardSortable Alpine.js component for
 * reordering widgets, and with WidgetConfigurator for adding and
 * editing widget settings.
 */
class DashboardEditor extends Component
{
    public Dashboard $dashboard;

    public Collection $widgets;

    public bool $editMode = false;

    public bool $showDeleteConfirmation = false;

    public ?string $widgetToDelete = null;

    /** @var string|null Widget ID being configured (for edit mode in WidgetConfigurator) */
    public ?string $configuringWidgetId = null;

    /** @var Collection Snapshot of widgets before entering edit mode */
    public Collection $originalWidgets;

    /** @var string Dashboard title (editable in edit mode) */
    public string $title;

    /** @var string|null Dashboard description (editable in edit mode) */
    public ?string $description = null;

    /** @var string|null Original title before entering edit mode (for cancel) */
    public ?string $originalTitle = null;

    /** @var string|null Original description before entering edit mode (for cancel) */
    public ?string $originalDescription = null;

    /**
     * Widget layouts before each change made in this edit session, newest last.
     * Locked so the client can't plant a layout to "undo" into.
     *
     * @var array<int, array<int, array<string, mixed>>>
     */
    #[Locked]
    public array $undoStack = [];

    /**
     * How many layout changes Undo can step back through.
     */
    public const MAX_UNDO_STEPS = 30;

    public function mount(Dashboard $dashboard): void
    {
        $this->dashboard = $dashboard;
        $this->title = $dashboard->title;
        $this->description = $dashboard->description;
        $this->loadWidgets();
        $this->originalWidgets = collect();
    }

    /**
     * Whether a widget is hidden for the event in context: contest widgets at
     * unscored events, and unscored-only defaults at contests.
     *
     * @param  array<string, mixed>  $widget
     */
    public function isHiddenForEvent(array $widget): bool
    {
        return app(DashboardService::class)->isHiddenForEvent($widget, $this->isScoredEvent);
    }

    #[Computed]
    public function isScoredEvent(): bool
    {
        return app(EventContextService::class)->isScored();
    }

    /**
     * Load widgets from the dashboard config and normalize them into a collection.
     */
    public function loadWidgets(): void
    {
        $config = $this->dashboard->config;

        if (is_array($config) && isset($config['widgets'])) {
            $this->widgets = collect($config['widgets'])->sortBy('order')->values();
        } elseif (is_array($config)) {
            $this->widgets = collect($config)->sortBy('order')->values();
        } else {
            $this->widgets = collect();
        }
    }

    /**
     * Enter or exit edit mode.
     */
    #[On('toggle-edit-mode')]
    public function toggleEditMode(): void
    {
        if ($this->editMode) {
            // Leaving edit mode without saving must restore the saved layout,
            // otherwise unsaved edits stay on screen looking saved.
            $this->cancelEdit();
        } else {
            $this->enterEditMode();
        }
    }

    /**
     * Enter edit mode, storing a snapshot of current widgets and metadata.
     */
    public function enterEditMode(): void
    {
        $this->ensureLayoutCanBeEdited();

        $this->originalWidgets = $this->widgets->map(fn ($w) => $w)->values();
        $this->originalTitle = $this->title;
        $this->originalDescription = $this->description;
        $this->undoStack = [];
        $this->editMode = true;
        $this->dispatch('edit-mode-changed', enabled: true);
    }

    /**
     * Exit edit mode without saving changes.
     */
    public function exitEditMode(): void
    {
        $this->editMode = false;
        $this->showDeleteConfirmation = false;
        $this->widgetToDelete = null;
        $this->configuringWidgetId = null;
        $this->undoStack = [];
        // Clear original snapshots
        $this->originalTitle = null;
        $this->originalDescription = null;
        $this->dispatch('edit-mode-changed', enabled: false);
    }

    /**
     * Save the current widget layout and dashboard metadata to the database.
     */
    public function saveLayout(DashboardService $service): void
    {
        $this->ensureLayoutCanBeEdited();

        // Validate title
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $config = $this->widgets->values()->toArray();

        // Convert empty string description to null
        $description = empty($this->description) ? null : $this->description;

        try {
            $service->updateDashboard($this->dashboard, [
                'title' => $this->title,
                'description' => $description,
                'config' => $config,
            ]);
            $this->dashboard->refresh();
            $this->originalTitle = $this->title;
            $this->originalDescription = $this->description;
            $this->exitEditMode();

            // Notify parent page to refresh dashboard data
            $this->dispatch('dashboard-saved');

            $this->dispatch('toast', [
                'title' => 'Success',
                'description' => 'Dashboard saved',
                'icon' => 'phosphor-check-circle',
                'css' => 'alert-success',
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('toast', [
                'title' => 'Validation Error',
                'description' => $e->getMessage(),
                'icon' => 'phosphor-warning',
                'css' => 'alert-error',
            ]);
        } catch (\OverflowException $e) {
            $this->dispatch('toast', [
                'title' => 'Limit Exceeded',
                'description' => $e->getMessage(),
                'icon' => 'phosphor-warning',
                'css' => 'alert-error',
            ]);
        }
    }

    /**
     * Discard changes and reload from the database.
     */
    public function cancelEdit(): void
    {
        if (! $this->editMode) {
            return;
        }

        if ($this->originalWidgets->isNotEmpty()) {
            $this->widgets = $this->originalWidgets->map(fn ($w) => $w)->values();
        } else {
            $this->loadWidgets();
        }

        // Restore original title and description if they were saved
        if ($this->originalTitle !== null) {
            $this->title = $this->originalTitle;
            $this->description = $this->originalDescription;
        }

        $this->exitEditMode();

        $this->dispatch('toast', [
            'title' => 'Cancelled',
            'description' => 'Changes discarded',
            'icon' => 'phosphor-x-circle',
            'css' => 'alert-warning',
        ]);
    }

    /**
     * Update widget order from the drag-and-drop component.
     *
     * Widgets missing from the client's list keep their relative order after
     * the listed ones, so a stale or partial list can never drop a widget.
     *
     * @param  array<int, string>  $widgetIds  Ordered array of widget IDs
     */
    public function reorderWidgets(array $widgetIds): void
    {
        if (! $this->editMode) {
            return;
        }

        $before = $this->layoutSnapshot();
        $widgetMap = $this->widgets->keyBy('id');
        $listedIds = collect($widgetIds)
            ->filter(fn ($widgetId) => is_string($widgetId) && $widgetMap->has($widgetId))
            ->unique()
            ->values();

        $this->widgets = $listedIds
            ->concat($widgetMap->keys()->diff($listedIds))
            ->values()
            ->map(function (string $widgetId, int $order) use ($widgetMap) {
                $widget = $widgetMap->get($widgetId);
                $widget['order'] = $order;

                return $widget;
            });

        $this->rememberForUndo($before);

        // Dispatch event to notify Alpine component of the new order
        $this->dispatch('widgets-reordered', widgetIds: $this->widgetIds);

        // Re-sync Alpine enabled state after Livewire re-render
        $this->dispatch('edit-mode-changed', enabled: $this->editMode);
    }

    /**
     * Toggle widget visibility.
     */
    public function toggleVisibility(string $widgetId): void
    {
        if (! $this->editMode) {
            return;
        }

        $before = $this->layoutSnapshot();

        $this->widgets = $this->widgets->map(function ($widget) use ($widgetId) {
            if ($widget['id'] === $widgetId) {
                $widget['visible'] = ! ($widget['visible'] ?? true);
            }

            return $widget;
        });

        $this->rememberForUndo($before);

        // Re-sync Alpine enabled state after Livewire re-render
        $this->dispatch('edit-mode-changed', enabled: $this->editMode);
    }

    /**
     * Resize a widget's column and row span.
     *
     * @param  string  $widgetId  The widget to resize
     * @param  int  $colSpan  Desired column span (clamped to 1-4)
     * @param  int  $rowSpan  Desired row span (clamped to Short-Tall, 2-6)
     */
    public function resizeWidget(string $widgetId, int $colSpan, int $rowSpan): void
    {
        if (! $this->editMode) {
            return;
        }

        $colSpan = $this->dashboardService()->clampColSpan($colSpan);
        $rowSpan = $this->dashboardService()->clampRowSpan($rowSpan);

        $before = $this->layoutSnapshot();
        $found = false;
        $this->widgets = $this->widgets->map(function ($widget) use ($widgetId, $colSpan, $rowSpan, &$found) {
            if ($widget['id'] === $widgetId) {
                $widget['col_span'] = $colSpan;
                $widget['row_span'] = $rowSpan;
                $found = true;
            }

            return $widget;
        });

        $this->rememberForUndo($before);

        if ($found) {
            $this->dispatch('edit-mode-changed', enabled: $this->editMode);
        }
    }

    /**
     * Confirm widget removal.
     */
    public function confirmRemoveWidget(string $widgetId): void
    {
        $this->widgetToDelete = $widgetId;
        $this->showDeleteConfirmation = true;
    }

    /**
     * Cancel widget removal.
     */
    public function cancelRemoveWidget(): void
    {
        $this->widgetToDelete = null;
        $this->showDeleteConfirmation = false;
    }

    /**
     * Remove a widget from the dashboard.
     */
    public function removeWidget(): void
    {
        if (! $this->editMode || ! $this->widgetToDelete) {
            return;
        }

        $before = $this->layoutSnapshot();

        $this->widgets = $this->widgets
            ->reject(fn ($widget) => $widget['id'] === $this->widgetToDelete)
            ->values()
            ->map(function ($widget, $index) {
                $widget['order'] = $index;

                return $widget;
            });

        $this->rememberForUndo($before);
        $this->cancelRemoveWidget();

        // Notify Alpine component of the updated widget order
        $this->dispatch('widgets-reordered', widgetIds: $this->widgetIds);

        // Re-sync Alpine enabled state after Livewire re-render
        $this->dispatch('edit-mode-changed', enabled: $this->editMode);

        $this->dispatch('toast', [
            'title' => 'Widget Removed',
            'description' => 'Widget has been removed from the dashboard',
            'icon' => 'phosphor-trash',
            'css' => 'alert-info',
        ]);
    }

    /**
     * Add a new widget to the dashboard.
     *
     * @param  string  $type  Widget type from config
     * @param  array<string, mixed>  $config  Widget configuration
     */
    public function addWidget(string $type, array $config = [], int $colSpan = 1, int $rowSpan = DashboardService::MIN_ROW_SPAN): void
    {
        if (! $this->editMode || ! $this->isKnownWidgetType($type)) {
            return;
        }

        $maxWidgets = $this->dashboardService()->maxWidgetsPerDashboard();
        if ($this->widgets->count() >= $maxWidgets) {
            $this->dispatch('toast', [
                'title' => 'Limit Reached',
                'description' => "Cannot add more than {$maxWidgets} widgets per dashboard.",
                'icon' => 'phosphor-warning',
                'css' => 'alert-error',
            ]);

            return;
        }

        $widgetId = $type.'-'.Str::random(8);
        $newWidget = [
            'id' => $widgetId,
            'type' => $type,
            'config' => $this->dashboardService()->sanitizeWidgetConfig($type, $config),
            'order' => $this->widgets->count(),
            'visible' => true,
            'col_span' => $this->dashboardService()->clampColSpan($colSpan),
            'row_span' => $this->dashboardService()->clampRowSpan($rowSpan),
        ];

        $before = $this->layoutSnapshot();
        $this->widgets->push($newWidget);
        $this->rememberForUndo($before);

        // Notify Alpine component of the new widget order
        $this->dispatch('widgets-reordered', widgetIds: $this->widgetIds);

        $this->dispatch('toast', [
            'title' => 'Widget Added',
            'description' => 'New widget has been added to the dashboard',
            'icon' => 'phosphor-plus-circle',
            'css' => 'alert-success',
        ]);
    }

    /**
     * Open WidgetConfigurator in 'add' mode.
     */
    public function openWidgetPicker(): void
    {
        $this->configuringWidgetId = null;
        $this->dispatch('open-widget-configurator', mode: 'add');
    }

    /**
     * Open WidgetConfigurator in 'edit' mode for a specific widget.
     */
    public function configureWidget(string $widgetId): void
    {
        if (! $this->editMode) {
            return;
        }

        $widget = $this->widgets->firstWhere('id', $widgetId);
        if (! $widget) {
            return;
        }

        $this->configuringWidgetId = $widgetId;
        $this->dispatch('open-widget-configurator',
            mode: 'edit',
            widgetType: $widget['type'],
            config: $widget['config'] ?? [],
            colSpan: $widget['col_span'] ?? 1,
            rowSpan: $widget['row_span'] ?? DashboardService::MIN_ROW_SPAN
        );
    }

    /**
     * Handle widget-configured event from WidgetConfigurator.
     */
    #[On('widget-configured')]
    public function handleWidgetConfigured(string $type, array $config, string $mode, int $colSpan = 1, int $rowSpan = DashboardService::MIN_ROW_SPAN): void
    {
        if (! $this->editMode || ! $this->isKnownWidgetType($type)) {
            return;
        }

        $service = $this->dashboardService();

        if ($mode === 'add') {
            $this->addWidget($type, $config, $colSpan, $rowSpan);
            $this->dispatch('edit-mode-changed', enabled: $this->editMode);
        } elseif ($mode === 'edit' && $this->configuringWidgetId) {
            $before = $this->layoutSnapshot();

            $this->widgets = $this->widgets->map(function ($widget) use ($service, $type, $config, $colSpan, $rowSpan) {
                if ($widget['id'] === $this->configuringWidgetId) {
                    $widget['type'] = $type;
                    $widget['config'] = $service->sanitizeWidgetConfig($type, $config);
                    $widget['col_span'] = $service->clampColSpan($colSpan);
                    $widget['row_span'] = $service->clampRowSpan($rowSpan);
                }

                return $widget;
            });

            $this->rememberForUndo($before);
            $this->configuringWidgetId = null;

            $this->dispatch('toast', [
                'title' => 'Widget Updated',
                'description' => 'Widget configuration saved',
                'icon' => 'phosphor-check-circle',
                'css' => 'alert-success',
            ]);

            $this->dispatch('edit-mode-changed', enabled: $this->editMode);
        }
    }

    /**
     * Step back to the layout before the most recent change in this edit session.
     */
    public function undo(): void
    {
        if (! $this->editMode || $this->undoStack === []) {
            return;
        }

        $this->widgets = collect(array_pop($this->undoStack));
        $this->configuringWidgetId = null;
        $this->cancelRemoveWidget();

        // Keep the drag-and-drop order and edit state in sync with the restored layout
        $this->dispatch('widgets-reordered', widgetIds: $this->widgetIds);
        $this->dispatch('edit-mode-changed', enabled: $this->editMode);
    }

    /**
     * Whether there is a change Undo can step back from.
     */
    public function canUndo(): bool
    {
        return $this->editMode && $this->undoStack !== [];
    }

    /**
     * The current layout as plain arrays, for undo history.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function layoutSnapshot(): array
    {
        return $this->widgets->values()->all();
    }

    /**
     * Record the layout as it was before a change, if the change did anything.
     *
     * @param  array<int, array<string, mixed>>  $before
     */
    protected function rememberForUndo(array $before): void
    {
        if ($before === $this->layoutSnapshot()) {
            return;
        }

        $this->undoStack[] = $before;

        if (count($this->undoStack) > self::MAX_UNDO_STEPS) {
            array_shift($this->undoStack);
        }
    }

    /**
     * Whether the type is one the dashboard can render and save.
     */
    protected function isKnownWidgetType(string $type): bool
    {
        return array_key_exists($type, config('dashboard.widget_types', []));
    }

    protected function dashboardService(): DashboardService
    {
        return app(DashboardService::class);
    }

    /**
     * Livewire key for a widget's component, changing whenever its settings
     * change so an edited widget remounts with the new settings instead of
     * keeping the ones it was first mounted with.
     *
     * @param  array<string, mixed>  $widget
     */
    public function widgetComponentKey(array $widget): string
    {
        return $widget['id'].'-'.substr(hash('xxh128', json_encode($widget['config'] ?? [])), 0, 12);
    }

    /**
     * Get widget IDs for Alpine.js sortable component.
     *
     * @return array<int, string>
     */
    public function getWidgetIdsProperty(): array
    {
        return $this->widgets->pluck('id')->values()->toArray();
    }

    /**
     * Get the widget type label from config.
     */
    public function getWidgetTypeLabel(string $type): string
    {
        return config("dashboard.widget_types.{$type}.name", ucfirst($type));
    }

    /**
     * Get the widget type icon from config.
     */
    public function getWidgetTypeIcon(string $type): string
    {
        return config("dashboard.widget_types.{$type}.icon", 'phosphor-cube');
    }

    /**
     * Station-mode operators can view the dashboard but not change its layout.
     */
    private function ensureLayoutCanBeEdited(): void
    {
        abort_if(StationMode::isActive(), 403, 'The dashboard layout cannot be edited in station mode.');
    }

    public function render(): View
    {
        return view('livewire.dashboard.dashboard-editor');
    }
}
