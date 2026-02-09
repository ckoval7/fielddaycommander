<?php

namespace App\Livewire\Dashboard;

use App\Models\Dashboard;
use App\Services\DashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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

    public function mount(Dashboard $dashboard): void
    {
        $this->dashboard = $dashboard;
        $this->title = $dashboard->title;
        $this->description = $dashboard->description;
        $this->loadWidgets();
        $this->originalWidgets = collect();
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
            $this->exitEditMode();
        } else {
            $this->enterEditMode();
        }
    }

    /**
     * Enter edit mode, storing a snapshot of current widgets and metadata.
     */
    public function enterEditMode(): void
    {
        $this->originalWidgets = $this->widgets->map(fn ($w) => $w)->values();
        $this->originalTitle = $this->title;
        $this->originalDescription = $this->description;
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
                'icon' => 'o-check-circle',
                'css' => 'alert-success',
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('toast', [
                'title' => 'Validation Error',
                'description' => $e->getMessage(),
                'icon' => 'o-exclamation-triangle',
                'css' => 'alert-error',
            ]);
        } catch (\OverflowException $e) {
            $this->dispatch('toast', [
                'title' => 'Limit Exceeded',
                'description' => $e->getMessage(),
                'icon' => 'o-exclamation-triangle',
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
            'icon' => 'o-x-circle',
            'css' => 'alert-warning',
        ]);
    }

    /**
     * Update widget order from the drag-and-drop component.
     *
     * @param  array<int, string>  $widgetIds  Ordered array of widget IDs
     */
    public function reorderWidgets(array $widgetIds): void
    {
        if (! $this->editMode) {
            return;
        }

        $widgetMap = $this->widgets->keyBy('id');
        $reordered = collect();

        foreach ($widgetIds as $order => $widgetId) {
            if ($widgetMap->has($widgetId)) {
                $widget = $widgetMap->get($widgetId);
                $widget['order'] = $order;
                $reordered->push($widget);
            }
        }

        $this->widgets = $reordered->values();

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

        $this->widgets = $this->widgets->map(function ($widget) use ($widgetId) {
            if ($widget['id'] === $widgetId) {
                $widget['visible'] = ! ($widget['visible'] ?? true);
            }

            return $widget;
        });

        // Re-sync Alpine enabled state after Livewire re-render
        $this->dispatch('edit-mode-changed', enabled: $this->editMode);
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

        $this->widgets = $this->widgets
            ->reject(fn ($widget) => $widget['id'] === $this->widgetToDelete)
            ->values()
            ->map(function ($widget, $index) {
                $widget['order'] = $index;

                return $widget;
            });

        $this->cancelRemoveWidget();

        // Notify Alpine component of the updated widget order
        $this->dispatch('widgets-reordered', widgetIds: $this->widgetIds);

        // Re-sync Alpine enabled state after Livewire re-render
        $this->dispatch('edit-mode-changed', enabled: $this->editMode);

        $this->dispatch('toast', [
            'title' => 'Widget Removed',
            'description' => 'Widget has been removed from the dashboard',
            'icon' => 'o-trash',
            'css' => 'alert-info',
        ]);
    }

    /**
     * Add a new widget to the dashboard.
     *
     * @param  string  $type  Widget type from config
     * @param  array<string, mixed>  $config  Widget configuration
     */
    public function addWidget(string $type, array $config = []): void
    {
        if (! $this->editMode) {
            return;
        }

        $maxWidgets = (int) config('dashboard.max_widgets_per_dashboard', 20);
        if ($this->widgets->count() >= $maxWidgets) {
            $this->dispatch('toast', [
                'title' => 'Limit Reached',
                'description' => "Cannot add more than {$maxWidgets} widgets per dashboard.",
                'icon' => 'o-exclamation-triangle',
                'css' => 'alert-error',
            ]);

            return;
        }

        $widgetId = $type.'-'.Str::random(8);
        $newWidget = [
            'id' => $widgetId,
            'type' => $type,
            'config' => $config,
            'order' => $this->widgets->count(),
            'visible' => true,
        ];

        $this->widgets->push($newWidget);

        // Notify Alpine component of the new widget order
        $this->dispatch('widgets-reordered', widgetIds: $this->widgetIds);

        $this->dispatch('toast', [
            'title' => 'Widget Added',
            'description' => 'New widget has been added to the dashboard',
            'icon' => 'o-plus-circle',
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
            config: $widget['config'] ?? []
        );
    }

    /**
     * Handle widget-configured event from WidgetConfigurator.
     */
    #[On('widget-configured')]
    public function handleWidgetConfigured(string $type, array $config, string $mode): void
    {
        if ($mode === 'add') {
            $this->addWidget($type, $config);
            // Re-sync Alpine enabled state after Livewire re-render
            $this->dispatch('edit-mode-changed', enabled: $this->editMode);
        } elseif ($mode === 'edit' && $this->configuringWidgetId) {
            $this->widgets = $this->widgets->map(function ($widget) use ($type, $config) {
                if ($widget['id'] === $this->configuringWidgetId) {
                    $widget['type'] = $type;
                    $widget['config'] = $config;
                }

                return $widget;
            });

            $this->configuringWidgetId = null;

            $this->dispatch('toast', [
                'title' => 'Widget Updated',
                'description' => 'Widget configuration saved',
                'icon' => 'o-check-circle',
                'css' => 'alert-success',
            ]);

            // Re-sync Alpine enabled state after Livewire re-render
            $this->dispatch('edit-mode-changed', enabled: $this->editMode);
        }
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
        return config("dashboard.widget_types.{$type}.icon", 'o-cube');
    }

    public function render(): View
    {
        return view('livewire.dashboard.dashboard-editor');
    }
}
