<?php

namespace App\Livewire\Dashboard;

use App\Models\Dashboard;
use App\Services\DashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class DashboardManager extends Component
{
    // Modal state
    public bool $showModal = false;

    public bool $showCreateForm = false;

    public bool $showDeleteConfirmation = false;

    public ?int $dashboardToDelete = null;

    // Dashboard collection
    public Collection $dashboards;

    // Form fields
    public string $newTitle = '';

    public string $newDescription = '';

    public ?int $copyFrom = null;

    public function mount(): void
    {
        $this->loadDashboards();
    }

    public function loadDashboards(): void
    {
        $this->dashboards = Dashboard::forUser(auth()->user())
            ->orderBy('is_default', 'desc')
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    #[On('open-modal')]
    public function openModal(?string $modalId = null): void
    {
        // Only open this modal if the modalId matches or is null
        if ($modalId === null || $modalId === 'dashboard-manager') {
            $this->showModal = true;
        }
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->showCreateForm = false;
        $this->resetForm();
    }

    public function openCreateForm(): void
    {
        $this->showCreateForm = true;
    }

    public function cancelCreate(): void
    {
        $this->showCreateForm = false;
        $this->resetForm();
    }

    public function createDashboard(DashboardService $service): void
    {
        $validated = $this->validate([
            'newTitle' => ['required', 'string', 'max:255'],
            'newDescription' => ['nullable', 'string'],
            'copyFrom' => ['nullable', 'integer', 'exists:dashboards,id'],
        ], [
            'newTitle.required' => 'Please provide a title for your dashboard.',
            'newTitle.max' => 'The title cannot exceed 255 characters.',
            'copyFrom.exists' => 'The selected dashboard to copy does not exist.',
        ]);

        try {
            if ($validated['copyFrom']) {
                // Duplicate existing dashboard
                $sourceDashboard = Dashboard::findOrFail($validated['copyFrom']);
                $service->duplicateDashboard($sourceDashboard, $validated['newTitle']);
            } else {
                // Create blank dashboard
                $service->createDashboard(
                    user: auth()->user(),
                    title: $validated['newTitle'],
                    description: $validated['newDescription']
                );
            }

            $this->loadDashboards();
            $this->cancelCreate();

            $this->dispatch('toast', [
                'title' => 'Success',
                'description' => 'Dashboard created successfully',
                'icon' => 'o-check-circle',
                'css' => 'alert-success',
            ]);
        } catch (\Exception $e) {
            $this->dispatch('toast', [
                'title' => 'Error',
                'description' => 'Failed to create dashboard: '.$e->getMessage(),
                'icon' => 'o-exclamation-triangle',
                'css' => 'alert-error',
            ]);
        }
    }

    public function duplicateDashboard(int $dashboardId, DashboardService $service): void
    {
        try {
            $dashboard = Dashboard::findOrFail($dashboardId);

            // Verify user owns this dashboard
            if ($dashboard->user_id !== auth()->id()) {
                throw new \Exception('You do not have permission to duplicate this dashboard.');
            }

            $newTitle = $dashboard->title.' (Copy)';
            $service->duplicateDashboard($dashboard, $newTitle);
            $this->loadDashboards();

            $this->dispatch('toast', [
                'title' => 'Success',
                'description' => 'Dashboard duplicated successfully',
                'icon' => 'o-check-circle',
                'css' => 'alert-success',
            ]);
        } catch (\Exception $e) {
            $this->dispatch('toast', [
                'title' => 'Error',
                'description' => 'Failed to duplicate dashboard: '.$e->getMessage(),
                'icon' => 'o-exclamation-triangle',
                'css' => 'alert-error',
            ]);
        }
    }

    public function confirmDelete(int $dashboardId): void
    {
        $this->dashboardToDelete = $dashboardId;
        $this->showDeleteConfirmation = true;
    }

    public function cancelDelete(): void
    {
        $this->dashboardToDelete = null;
        $this->showDeleteConfirmation = false;
    }

    public function deleteDashboard(DashboardService $service): void
    {
        if (! $this->dashboardToDelete) {
            return;
        }

        try {
            $dashboard = Dashboard::findOrFail($this->dashboardToDelete);

            // Verify user owns this dashboard
            if ($dashboard->user_id !== auth()->id()) {
                throw new \Exception('You do not have permission to delete this dashboard.');
            }

            $service->deleteDashboard($dashboard);
            $this->loadDashboards();
            $this->cancelDelete();

            $this->dispatch('toast', [
                'title' => 'Success',
                'description' => 'Dashboard deleted successfully',
                'icon' => 'o-check-circle',
                'css' => 'alert-success',
            ]);
        } catch (\Exception $e) {
            $this->dispatch('toast', [
                'title' => 'Error',
                'description' => 'Failed to delete dashboard: '.$e->getMessage(),
                'icon' => 'o-exclamation-triangle',
                'css' => 'alert-error',
            ]);

            $this->cancelDelete();
        }
    }

    public function setDefault(int $dashboardId, DashboardService $service): void
    {
        try {
            $dashboard = Dashboard::findOrFail($dashboardId);

            // Verify user owns this dashboard
            if ($dashboard->user_id !== auth()->id()) {
                throw new \Exception('You do not have permission to modify this dashboard.');
            }

            $service->setAsDefault($dashboard);
            $this->loadDashboards();

            $this->dispatch('toast', [
                'title' => 'Success',
                'description' => 'Default dashboard updated',
                'icon' => 'o-check-circle',
                'css' => 'alert-success',
            ]);
        } catch (\Exception $e) {
            $this->dispatch('toast', [
                'title' => 'Error',
                'description' => 'Failed to set default dashboard: '.$e->getMessage(),
                'icon' => 'o-exclamation-triangle',
                'css' => 'alert-error',
            ]);
        }
    }

    public function resetForm(): void
    {
        $this->reset(['newTitle', 'newDescription', 'copyFrom']);
    }

    public function render(): View
    {
        return view('livewire.dashboard.dashboard-manager');
    }
}
