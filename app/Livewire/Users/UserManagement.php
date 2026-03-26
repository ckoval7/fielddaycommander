<?php

namespace App\Livewire\Users;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitation as UserInvitationNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

class UserManagement extends Component
{
    private const BULK_LIMIT_ERROR = 'Bulk actions limited to 50 users. Please select fewer users.';

    use WithPagination;

    // Search and filters
    public string $search = '';

    public ?string $roleFilter = null;

    public ?string $statusFilter = null;

    // Modal state
    public bool $showModal = false;

    public bool $showLockModal = false;

    public bool $showResetModal = false;

    public bool $showDeleteModal = false;

    // User being edited
    public ?int $editingUserId = null;

    public string $call_sign = '';

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public ?string $license_class = null;

    public ?int $role_id = null;

    public bool $inviteMode = true;

    public string $password = '';

    public string $password_confirmation = '';

    // Lock modal
    public ?int $lockingUserId = null;

    public ?string $lockExpiry = null;

    // Reset password modal
    public ?int $resettingUserId = null;

    public bool $sendResetEmail = true;

    public string $resetPassword = '';

    public string $resetPassword_confirmation = '';

    // Delete confirmation
    public ?int $deletingUserId = null;

    // Bulk actions
    public array $selectedUsers = [];

    public bool $selectAll = false;

    public ?int $bulk_role_id = null;

    public ?string $bulkLockExpiry = null;

    public function mount(): void
    {
        Gate::authorize('manage-users');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSelectAll($value): void
    {
        if ($value) {
            $this->selectedUsers = $this->users->pluck('id')->toArray();
        } else {
            $this->selectedUsers = [];
        }
    }

    #[Computed]
    public function users()
    {
        return User::query()
            ->with('roles')
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('call_sign', 'like', "%{$this->search}%")
                        ->orWhere('first_name', 'like', "%{$this->search}%")
                        ->orWhere('last_name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%");
                });
            })
            ->when($this->roleFilter, function ($query) {
                $query->whereHas('roles', function ($q) {
                    $q->where('name', $this->roleFilter);
                });
            })
            ->when($this->statusFilter, function ($query) {
                match ($this->statusFilter) {
                    'active' => $query->whereNull('account_locked_at'),
                    'locked' => $query->whereNotNull('account_locked_at'),
                    '2fa_enabled' => $query->whereNotNull('two_factor_secret'),
                    'password_reset_required' => $query->where('requires_password_change', true),
                    default => $query,
                };
            })
            ->orderBy('created_at', 'desc')
            ->paginate(15);
    }

    #[Computed]
    public function roles()
    {
        return Role::orderBy('name')->get();
    }

    public function openCreateModal(): void
    {
        $this->reset([
            'editingUserId', 'call_sign', 'first_name', 'last_name',
            'email', 'license_class', 'role_id', 'password',
            'password_confirmation', 'inviteMode',
        ]);
        $this->inviteMode = true;
        $this->showModal = true;
    }

    public function openEditModal(int $userId): void
    {
        $user = User::with('roles')->findOrFail($userId);

        $this->editingUserId = $user->id;
        $this->call_sign = $user->call_sign;
        $this->first_name = $user->first_name;
        $this->last_name = $user->last_name;
        $this->email = $user->email;
        $this->license_class = $user->license_class;
        $this->role_id = $user->roles->first()?->id ?? $this->roles->first()->id;

        $this->showModal = true;
    }

    public function saveUser(): void
    {
        Gate::authorize('manage-users');

        if ($this->editingUserId) {
            $this->updateUser();
        } else {
            $this->createUser();
        }
    }

    protected function createUser(): void
    {
        $validated = $this->validate([
            'call_sign' => ['required', 'string', 'max:255', 'unique:users'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'license_class' => ['nullable', 'in:Technician,General,Advanced,Extra'],
            'role_id' => ['required', 'exists:roles,id'],
            'password' => ['required_if:inviteMode,false', 'confirmed', Password::defaults()],
        ], [
            'call_sign.unique' => 'This call sign is already registered',
            'email.unique' => 'This email is already registered',
        ]);

        $user = User::create([
            'call_sign' => $validated['call_sign'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'license_class' => $validated['license_class'],
            'password' => $this->inviteMode ? Hash::make(str()->random(32)) : Hash::make($validated['password']),
        ]);

        $role = Role::find($validated['role_id']);
        $user->assignRole($role);

        AuditLog::log('user.created', auditable: $user, newValues: [
            'call_sign' => $user->call_sign,
            'email' => $user->email,
            'role' => $role->name,
        ]);

        if ($this->inviteMode) {
            try {
                $token = Str::random(64);
                $admin = auth()->user();
                $adminName = trim($admin->first_name.' '.$admin->last_name) ?: $admin->call_sign;

                UserInvitation::create([
                    'user_id' => $user->id,
                    'token' => $token,
                    'expires_at' => now()->addHours(72),
                ]);

                $user->notify(new UserInvitationNotification($token, $adminName));

                AuditLog::log('user.invitation.sent', auditable: $user, newValues: [
                    'email' => $user->email,
                ]);

                $this->showModal = false;
                $this->reset(['selectedUsers', 'selectAll']);
                $this->dispatch('toast', title: 'Success', description: 'User created and invitation email sent', icon: 'o-envelope', css: 'alert-success');
            } catch (\Exception $e) {
                Log::error('Failed to send user invitation email', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'user_call_sign' => $user->call_sign,
                    'admin_id' => auth()->id(),
                    'error' => $e->getMessage(),
                ]);

                $this->dispatch('toast', title: 'Warning', description: 'User created but invitation email failed to send. Check application logs for details.', icon: 'o-exclamation-triangle', css: 'alert-warning');
            }
        } else {
            $this->showModal = false;
            $this->reset(['selectedUsers', 'selectAll']);
            $this->dispatch('toast', title: 'Success', description: 'User created successfully', icon: 'o-check-circle', css: 'alert-success');
        }
    }

    protected function updateUser(): void
    {
        $validated = $this->validate([
            'call_sign' => ['required', 'string', 'max:255', Rule::unique('users')->ignore($this->editingUserId)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->editingUserId)],
            'license_class' => ['nullable', 'in:Technician,General,Advanced,Extra'],
            'role_id' => ['required', 'exists:roles,id'],
        ], [
            'call_sign.unique' => 'This call sign is already registered',
            'email.unique' => 'This email is already registered',
        ]);

        $user = User::findOrFail($this->editingUserId);

        $oldValues = [
            'call_sign' => $user->call_sign,
            'email' => $user->email,
            'role' => $user->roles->first()?->name,
        ];

        $user->update([
            'call_sign' => $validated['call_sign'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'license_class' => $validated['license_class'],
        ]);

        $role = Role::find($validated['role_id']);
        $user->syncRoles([$role]);

        AuditLog::log('user.updated', auditable: $user, oldValues: $oldValues, newValues: [
            'call_sign' => $validated['call_sign'],
            'email' => $validated['email'],
            'role' => $role->name,
        ]);

        $this->showModal = false;
        $this->dispatch('toast', title: 'Success', description: 'User updated successfully', icon: 'o-check-circle', css: 'alert-success');
    }

    public function openLockModal(int $userId): void
    {
        $this->lockingUserId = $userId;
        $this->lockExpiry = null;
        $this->showLockModal = true;
    }

    public function lockAccount(): void
    {
        Gate::authorize('manage-users');

        if (auth()->id() === $this->lockingUserId) {
            $this->dispatch('toast', title: 'Error', description: 'You cannot lock your own account', icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        $this->validate([
            'lockExpiry' => ['nullable', 'date', 'after:now'],
        ], [
            'lockExpiry.after' => 'Lock expiry must be in the future',
        ]);

        $user = User::findOrFail($this->lockingUserId);
        $user->update([
            'account_locked_at' => $this->lockExpiry ?? now(),
        ]);

        AuditLog::log('user.locked', auditable: $user, newValues: [
            'call_sign' => $user->call_sign,
            'expires_at' => $this->lockExpiry,
        ], isCritical: true);

        $this->showLockModal = false;
        $this->reset(['lockingUserId', 'lockExpiry']);
        $this->dispatch('toast', title: 'Success', description: 'Account locked', icon: 'o-lock-closed', css: 'alert-success');
    }

    public function unlockAccount(int $userId): void
    {
        Gate::authorize('manage-users');

        $user = User::findOrFail($userId);
        $user->update(['account_locked_at' => null]);

        AuditLog::log('user.unlocked', auditable: $user, newValues: [
            'call_sign' => $user->call_sign,
        ]);

        $this->dispatch('toast', title: 'Success', description: 'Account unlocked', icon: 'o-lock-open', css: 'alert-success');
    }

    public function forcePasswordReset(int $userId): void
    {
        Gate::authorize('manage-users');

        $user = User::findOrFail($userId);
        $user->update(['requires_password_change' => true]);

        AuditLog::log('user.password.force_reset', auditable: $user, newValues: [
            'call_sign' => $user->call_sign,
        ]);

        $this->dispatch('toast', title: 'Success', description: 'User will be prompted to change password on next login', icon: 'o-key', css: 'alert-success');
    }

    public function openResetModal(int $userId): void
    {
        $this->resettingUserId = $userId;
        $this->sendResetEmail = true;
        $this->resetPassword = '';
        $this->resetPassword_confirmation = '';
        $this->showResetModal = true;
    }

    public function resetPassword(): void
    {
        Gate::authorize('manage-users');

        if (! $this->sendResetEmail) {
            $this->validate([
                'resetPassword' => ['required', 'confirmed', Password::defaults()],
            ]);

            $user = User::findOrFail($this->resettingUserId);
            $user->update([
                'password' => Hash::make($this->resetPassword),
                'requires_password_change' => true,
            ]);

            AuditLog::log('user.password.reset_by_admin', auditable: $user, newValues: [
                'call_sign' => $user->call_sign,
            ], isCritical: true);

            $this->showResetModal = false;
            $this->dispatch('toast', title: 'Success', description: 'Password reset successfully', icon: 'o-key', css: 'alert-success');
        } else {
            // TODO: Send password reset email
            $this->showResetModal = false;
            $this->dispatch('toast', title: 'Success', description: 'Password reset email sent', icon: 'o-envelope', css: 'alert-success');
        }

        $this->reset(['resettingUserId', 'sendResetEmail', 'resetPassword', 'resetPassword_confirmation']);
    }

    public function openDeleteModal(int $userId): void
    {
        $this->deletingUserId = $userId;
        $this->showDeleteModal = true;
    }

    public function deleteUser(): void
    {
        Gate::authorize('manage-users');

        if (auth()->id() === $this->deletingUserId) {
            $this->dispatch('toast', title: 'Error', description: 'You cannot delete your own account', icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        $user = User::findOrFail($this->deletingUserId);

        AuditLog::log('user.deleted', auditable: $user, oldValues: [
            'call_sign' => $user->call_sign,
            'email' => $user->email,
        ], isCritical: true);

        $user->delete();

        $this->showDeleteModal = false;
        $this->reset('deletingUserId');
        $this->dispatch('toast', title: 'Success', description: 'User deleted', icon: 'o-trash', css: 'alert-success');
    }

    public function bulkAssignRole(): void
    {
        Gate::authorize('manage-users');

        if (empty($this->selectedUsers) || ! $this->bulk_role_id) {
            return;
        }

        if (count($this->selectedUsers) > 50) {
            $this->dispatch('toast', title: 'Error', description: self::BULK_LIMIT_ERROR, icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        $role = Role::find($this->bulk_role_id);
        $userIds = $this->selectedUsers;
        User::whereIn('id', $userIds)
            ->with('roles')
            ->get()
            ->each(function ($user) use ($role) {
                $oldRole = $user->roles->first()?->name;
                $user->syncRoles([$role]);

                AuditLog::log('role.assigned', auditable: $user, oldValues: [
                    'role' => $oldRole,
                ], newValues: [
                    'role' => $role->name,
                    'call_sign' => $user->call_sign,
                ]);
            });

        $count = count($userIds);
        $this->reset(['selectedUsers', 'selectAll', 'bulk_role_id']);
        $this->dispatch('toast', title: 'Success', description: "Role assigned to {$count} users", icon: 'o-check-circle', css: 'alert-success');
    }

    public function bulkLockAccounts(): void
    {
        Gate::authorize('manage-users');

        if (empty($this->selectedUsers)) {
            return;
        }

        if (count($this->selectedUsers) > 50) {
            $this->dispatch('toast', title: 'Error', description: self::BULK_LIMIT_ERROR, icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        if (in_array(auth()->id(), $this->selectedUsers)) {
            $this->dispatch('toast', title: 'Error', description: 'You cannot lock your own account', icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        $userIds = $this->selectedUsers;

        User::whereIn('id', $userIds)->update([
            'account_locked_at' => $this->bulkLockExpiry ?? now(),
        ]);

        $users = User::whereIn('id', $userIds)->get();
        foreach ($users as $user) {
            AuditLog::log('user.locked', auditable: $user, newValues: [
                'call_sign' => $user->call_sign,
                'expires_at' => $this->bulkLockExpiry,
                'bulk_action' => true,
            ], isCritical: true);
        }

        $count = count($userIds);
        $this->reset(['selectedUsers', 'selectAll', 'bulkLockExpiry']);
        $this->dispatch('toast', title: 'Success', description: "{$count} accounts locked", icon: 'o-lock-closed', css: 'alert-success');
    }

    public function bulkUnlockAccounts(): void
    {
        Gate::authorize('manage-users');

        if (empty($this->selectedUsers)) {
            return;
        }

        if (count($this->selectedUsers) > 50) {
            $this->dispatch('toast', title: 'Error', description: self::BULK_LIMIT_ERROR, icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        $userIds = $this->selectedUsers;

        User::whereIn('id', $userIds)->update([
            'account_locked_at' => null,
        ]);

        $users = User::whereIn('id', $userIds)->get();
        foreach ($users as $user) {
            AuditLog::log('user.unlocked', auditable: $user, newValues: [
                'call_sign' => $user->call_sign,
                'bulk_action' => true,
            ]);
        }

        $count = count($userIds);
        $this->reset(['selectedUsers', 'selectAll']);
        $this->dispatch('toast', title: 'Success', description: "{$count} accounts unlocked", icon: 'o-lock-open', css: 'alert-success');
    }

    public function bulkDeleteUsers(): void
    {
        Gate::authorize('manage-users');

        if (empty($this->selectedUsers)) {
            return;
        }

        if (count($this->selectedUsers) > 50) {
            $this->dispatch('toast', title: 'Error', description: self::BULK_LIMIT_ERROR, icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        if (in_array(auth()->id(), $this->selectedUsers)) {
            $this->dispatch('toast', title: 'Error', description: 'You cannot delete your own account', icon: 'o-x-circle', css: 'alert-error');

            return;
        }

        $userIds = $this->selectedUsers;

        $users = User::whereIn('id', $userIds)->get();
        foreach ($users as $user) {
            AuditLog::log('user.deleted', auditable: $user, oldValues: [
                'call_sign' => $user->call_sign,
                'email' => $user->email,
                'bulk_action' => true,
            ], isCritical: true);
        }

        User::whereIn('id', $userIds)->delete();

        $count = count($userIds);
        $this->reset(['selectedUsers', 'selectAll']);
        $this->dispatch('toast', title: 'Success', description: "{$count} users deleted", icon: 'o-trash', css: 'alert-success');
    }

    public function render()
    {
        return view('livewire.users.user-management')->layout('layouts.app');
    }
}
