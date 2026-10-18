<?php

namespace App\Livewire\Components;

use App\Support\DeveloperMode;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Spatie\Permission\Models\Role;

class DevRoleSwitcher extends Component
{
    public string $role = '';

    public string $callSign = '';

    public bool $isActive = false;

    public function mount(): void
    {
        if (! DeveloperMode::allowsRoleOverride() || ! auth()->check()) {
            return;
        }

        $this->role = session('dev_role_override', '');
        $this->callSign = session('dev_callsign_override', '');
        $this->updateActiveState();
    }

    public function updatedRole(string $value): void
    {
        $this->ensureDeveloperModeEnabled();

        if ($value === '') {
            session()->forget('dev_role_override');
        } else {
            session(['dev_role_override' => $value]);
        }

        $this->updateActiveState();
        $this->js('window.location.reload()');
    }

    public function updatedCallSign(string $value): void
    {
        $this->ensureDeveloperModeEnabled();

        if ($value === '') {
            session()->forget('dev_callsign_override');
        } else {
            session(['dev_callsign_override' => $value]);
        }

        $this->updateActiveState();
    }

    public function resetOverrides(): void
    {
        $this->ensureDeveloperModeEnabled();

        session()->forget(['dev_role_override', 'dev_callsign_override']);
        $this->role = '';
        $this->callSign = '';
        $this->isActive = false;
        $this->js('window.location.reload()');
    }

    /**
     * Refuse override changes unless developer mode is enabled and a user is signed in.
     */
    protected function ensureDeveloperModeEnabled(): void
    {
        abort_unless(DeveloperMode::allowsRoleOverride() && auth()->check(), 404);
    }

    protected function updateActiveState(): void
    {
        $this->isActive = session()->has('dev_role_override') || session()->has('dev_callsign_override');
    }

    /**
     * Get the available roles from the Spatie roles table.
     *
     * @return array<string>
     */
    protected function getAvailableRoles(): array
    {
        return Role::query()
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    public function render(): View
    {
        $roles = DeveloperMode::allowsRoleOverride() ? $this->getAvailableRoles() : [];

        return view('livewire.components.dev-role-switcher', compact('roles'));
    }
}
