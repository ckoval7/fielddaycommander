<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Users;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitation as UserInvitationNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

class UserManagement extends Component
{
    private const BULK_LIMIT_ERROR = 'Please select no more than 50 users for a bulk action.';

    private const SYSTEM_ACCOUNT_PROTECTED = 'The SYSTEM account is protected and can\'t be changed.';

    private const SYSTEM_ACCOUNT_SKIPPED = 'The SYSTEM account is protected and was skipped.';

    private const FIRST_NAME_REQUIRED = 'Enter a first name.';

    private const LAST_NAME_REQUIRED = 'Enter a last name.';

    private const EMAIL_REQUIRED = 'Enter an email address.';

    private const EMAIL_INVALID = 'Enter a valid email address.';

    private const EMAIL_TAKEN = 'This email address is already registered.';

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

    public bool $showBulkDeleteModal = false;

    public bool $showActivateModal = false;

    // User being edited
    public ?int $editingUserId = null;

    public string $call_sign = '';

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public ?string $license_class = null;

    public bool $is_youth = false;

    public bool $is_cpr_aed_trained = false;

    public ?int $role_id = null;

    public bool $inviteMode = true;

    public string $password = '';

    public string $password_confirmation = '';

    public bool $requirePasswordChange = true;

    // Lock modal
    public ?int $lockingUserId = null;

    // Reset password modal
    public ?int $resettingUserId = null;

    public string $resetMethod = 'manual';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    // Activate ghost modal
    public ?int $activatingUserId = null;

    public string $activateEmail = '';

    public string $activateFirstName = '';

    public string $activateLastName = '';

    public string $activatePassword = '';

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
                    'ghost' => $query->where('is_ghost', true),
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
            'email', 'license_class', 'is_youth', 'is_cpr_aed_trained', 'role_id', 'password',
            'password_confirmation', 'inviteMode', 'requirePasswordChange',
        ]);
        $this->inviteMode = (bool) config('mail.email_configured');
        $this->role_id = $this->roles->first()?->id;
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
        $this->is_youth = (bool) $user->is_youth;
        $this->is_cpr_aed_trained = (bool) $user->is_cpr_aed_trained;
        $this->role_id = $user->roles->first()?->id ?? $this->roles->first()->id;

        $this->showModal = true;
    }

    public function saveUser(): void
    {
        Gate::authorize('manage-users');

        $this->call_sign = mb_strtoupper(trim($this->call_sign));

        if ($this->editingUserId) {
            $this->updateUser();
        } else {
            $this->createUser();
        }
    }

    protected function createUser(): void
    {
        if (! config('mail.email_configured')) {
            $this->inviteMode = false;
        }

        $validated = $this->validate([
            'call_sign' => ['required', 'string', 'max:12', 'unique:users'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'license_class' => ['nullable', 'in:Technician,General,Advanced,Extra'],
            'is_youth' => ['boolean'],
            'is_cpr_aed_trained' => ['boolean'],
            'role_id' => ['required', 'exists:roles,id'],
            'password' => ['required_if:inviteMode,false', 'confirmed', Password::defaults()],
        ], [
            'call_sign.required' => 'Enter a callsign.',
            'first_name.required' => self::FIRST_NAME_REQUIRED,
            'last_name.required' => self::LAST_NAME_REQUIRED,
            'email.required' => self::EMAIL_REQUIRED,
            'email.email' => self::EMAIL_INVALID,
            'role_id.required' => 'Select a role.',
            'call_sign.unique' => 'This callsign is already registered.',
            'email.unique' => self::EMAIL_TAKEN,
            'password.required_if' => 'Enter a password or send an invitation.',
        ]);

        $user = User::create([
            'call_sign' => $validated['call_sign'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'license_class' => $validated['license_class'],
            'is_youth' => $this->is_youth,
            'is_cpr_aed_trained' => $this->is_cpr_aed_trained,
            'password' => $this->inviteMode ? Hash::make(str()->random(32)) : Hash::make($validated['password']),
            'requires_password_change' => ! $this->inviteMode && $this->requirePasswordChange,
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
                unset($this->users);
                $this->dispatch('toast', title: 'Success', description: 'User created and invitation email sent.', icon: 'phosphor-envelope', css: 'alert-success');
            } catch (\Exception $e) {
                Log::error('Failed to send user invitation email', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'user_call_sign' => $user->call_sign,
                    'admin_id' => auth()->id(),
                    'error' => $e->getMessage(),
                ]);

                $this->showModal = false;
                $this->reset(['selectedUsers', 'selectAll']);
                unset($this->users);
                $this->dispatch('toast', title: 'Warning', description: "User created, but the invitation email could not be sent. Open {$user->call_sign} and use Reset Password to send an email again, or check the application logs.", icon: 'phosphor-warning', css: 'alert-warning');
            }
        } else {
            $this->showModal = false;
            $this->reset(['selectedUsers', 'selectAll']);
            unset($this->users);
            $this->dispatch('toast', title: 'Success', description: 'User created.', icon: 'phosphor-check-circle', css: 'alert-success');
        }
    }

    protected function updateUser(): void
    {
        $validated = $this->validate([
            'call_sign' => ['required', 'string', 'max:12', Rule::unique('users')->ignore($this->editingUserId)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->editingUserId)],
            'license_class' => ['nullable', 'in:Technician,General,Advanced,Extra'],
            'is_youth' => ['boolean'],
            'is_cpr_aed_trained' => ['boolean'],
            'role_id' => ['required', 'exists:roles,id'],
        ], [
            'call_sign.required' => 'Enter a callsign.',
            'first_name.required' => self::FIRST_NAME_REQUIRED,
            'last_name.required' => self::LAST_NAME_REQUIRED,
            'email.required' => self::EMAIL_REQUIRED,
            'email.email' => self::EMAIL_INVALID,
            'role_id.required' => 'Select a role.',
            'call_sign.unique' => 'This callsign is already registered.',
            'email.unique' => self::EMAIL_TAKEN,
        ]);

        $user = User::findOrFail($this->editingUserId);

        // SYSTEM account role is immutable
        if ($user->isSystemUser()) {
            $currentRoleId = $user->roles->first()?->id;
            if ($currentRoleId && (int) $validated['role_id'] !== $currentRoleId) {
                $this->dispatch('toast', title: 'Error', description: 'The SYSTEM account role cannot be changed.', icon: 'phosphor-x-circle', css: 'alert-error');

                return;
            }
        }

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
            'is_youth' => $this->is_youth,
            'is_cpr_aed_trained' => $this->is_cpr_aed_trained,
        ]);

        $role = Role::find($validated['role_id']);
        $user->syncRoles([$role]);

        AuditLog::log('user.updated', auditable: $user, oldValues: $oldValues, newValues: [
            'call_sign' => $validated['call_sign'],
            'email' => $validated['email'],
            'role' => $role->name,
        ]);

        $this->showModal = false;

        unset($this->users);

        $this->dispatch('toast', title: 'Success', description: 'User updated.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    public function openLockModal(int $userId): void
    {
        $this->lockingUserId = $userId;
        $this->showLockModal = true;
    }

    public function lockAccount(): void
    {
        Gate::authorize('manage-users');

        if (auth()->id() === $this->lockingUserId) {
            $this->dispatch('toast', title: 'Error', description: 'You cannot lock your own account', icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        $user = User::findOrFail($this->lockingUserId);
        $user->update([
            'account_locked_at' => now(),
            'approval_requested_at' => null,
        ]);

        AuditLog::log('user.locked', auditable: $user, newValues: [
            'call_sign' => $user->call_sign,
        ], isCritical: true);

        $this->showLockModal = false;
        $this->reset(['lockingUserId']);
        $this->dispatch('toast', title: 'Success', description: 'Account locked', icon: 'phosphor-lock', css: 'alert-success');
    }

    public function unlockAccount(int $userId): void
    {
        Gate::authorize('manage-users');

        $user = User::findOrFail($userId);
        $user->update(['account_locked_at' => null, 'approval_requested_at' => null]);

        AuditLog::log('user.unlocked', auditable: $user, newValues: [
            'call_sign' => $user->call_sign,
        ]);

        $this->dispatch('toast', title: 'Success', description: 'Account unlocked', icon: 'phosphor-lock-open', css: 'alert-success');
    }

    public function forcePasswordReset(int $userId): void
    {
        Gate::authorize('manage-users');

        $user = User::findOrFail($userId);
        $user->update(['requires_password_change' => true]);

        AuditLog::log('user.password.force_reset', auditable: $user, newValues: [
            'call_sign' => $user->call_sign,
        ]);

        $this->dispatch('toast', title: 'Success', description: 'The user must change their password at next sign-in.', icon: 'phosphor-key', css: 'alert-success');
    }

    public function openResetModal(int $userId): void
    {
        $this->resettingUserId = $userId;
        $this->resetMethod = 'manual';
        $this->newPassword = Str::password(16);
        $this->newPassword_confirmation = '';
        $this->showResetModal = true;
    }

    public function openActivateModal(int $userId): void
    {
        $user = User::where('is_ghost', true)->findOrFail($userId);

        $this->resetValidation();
        $this->activatingUserId = $user->id;
        $this->activateEmail = '';
        $this->activateFirstName = $user->first_name === $user->call_sign ? '' : $user->first_name;
        $this->activateLastName = $user->last_name === '(Imported)' ? '' : $user->last_name;
        $this->activatePassword = Str::password(16);
        $this->showActivateModal = true;
    }

    /**
     * Turn a ghost account into a real login with an email and temporary password.
     */
    public function activateGhost(): void
    {
        Gate::authorize('manage-users');

        $user = User::where('is_ghost', true)->findOrFail($this->activatingUserId);

        $this->activateEmail = strtolower(trim($this->activateEmail));

        $this->validate([
            'activateEmail' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($user->id)->withoutTrashed()],
            'activateFirstName' => ['required', 'string', 'max:50'],
            'activateLastName' => ['required', 'string', 'max:50'],
            'activatePassword' => ['required', 'string', 'min:8'],
        ], [
            'activateEmail.required' => self::EMAIL_REQUIRED,
            'activateEmail.email' => self::EMAIL_INVALID,
            'activateEmail.unique' => self::EMAIL_TAKEN,
            'activateFirstName.required' => self::FIRST_NAME_REQUIRED,
            'activateLastName.required' => self::LAST_NAME_REQUIRED,
        ], attributes: [
            'activateEmail' => 'email',
            'activateFirstName' => 'first name',
            'activateLastName' => 'last name',
            'activatePassword' => 'password',
        ]);

        $user->update([
            'is_ghost' => false,
            'email' => $this->activateEmail,
            'email_verified_at' => now(),
            'first_name' => $this->activateFirstName,
            'last_name' => $this->activateLastName,
            'password' => Hash::make($this->activatePassword),
            'requires_password_change' => true,
            'account_locked_at' => null,
            'approval_requested_at' => null,
            'failed_login_attempts' => 0,
            'user_role' => 'user',
        ]);

        if ($user->roles()->doesntExist()) {
            $user->assignRole('Operator');
        }

        AuditLog::log('user.ghost_activated', auditable: $user, newValues: [
            'call_sign' => $user->call_sign,
            'email' => $user->email,
        ], isCritical: true);

        $this->showActivateModal = false;
        $this->reset(['activatingUserId', 'activateEmail', 'activateFirstName', 'activateLastName', 'activatePassword']);
        unset($this->users);

        $this->dispatch('toast', title: 'Account Activated', description: "{$user->call_sign} can now sign in with the temporary password.", icon: 'phosphor-user-check', css: 'alert-success');
    }

    public function resetPassword(): void
    {
        Gate::authorize('manage-users');

        if ($this->resetMethod === 'manual') {
            $this->validate([
                'newPassword' => ['required', 'string', 'min:8'],
            ]);

            $user = User::findOrFail($this->resettingUserId);
            $user->update([
                'password' => Hash::make($this->newPassword),
                'requires_password_change' => true,
            ]);

            AuditLog::log('user.password.reset_by_admin', auditable: $user, newValues: [
                'call_sign' => $user->call_sign,
            ], isCritical: true);

            $this->showResetModal = false;
            $this->dispatch('toast', title: 'Success', description: 'Password reset.', icon: 'phosphor-key', css: 'alert-success');
        } else {
            $user = User::findOrFail($this->resettingUserId);

            $status = PasswordBroker::sendResetLink(['email' => $user->email]);

            if ($status === PasswordBroker::RESET_LINK_SENT) {
                AuditLog::log('user.password.reset_email_sent', auditable: $user, newValues: [
                    'call_sign' => $user->call_sign,
                ]);

                $this->showResetModal = false;
                $this->dispatch('toast', title: 'Success', description: 'Password reset email sent.', icon: 'phosphor-envelope', css: 'alert-success');
            } else {
                $this->dispatch('toast', title: 'Error', description: __($status), icon: 'phosphor-x-circle', css: 'alert-error');

                return;
            }
        }

        $this->reset(['resettingUserId', 'resetMethod', 'newPassword', 'newPassword_confirmation']);
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
            $this->dispatch('toast', title: 'Error', description: 'You cannot delete your own account', icon: 'phosphor-x-circle', css: 'alert-error');

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
        $this->dispatch('toast', title: 'Success', description: 'User deleted', icon: 'phosphor-trash', css: 'alert-success');
    }

    public function bulkAssignRole(): void
    {
        Gate::authorize('manage-users');

        if (empty($this->selectedUsers) || ! $this->bulk_role_id) {
            return;
        }

        if (count($this->selectedUsers) > 50) {
            $this->dispatch('toast', title: 'Error', description: self::BULK_LIMIT_ERROR, icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        $role = Role::find($this->bulk_role_id);
        $users = User::whereIn('id', $this->selectedUsers)
            ->excludeSystem()
            ->with('roles')
            ->get();
        $count = $users->count();
        $users->each(function ($user) use ($role) {
            $oldRole = $user->roles->first()?->name;
            $user->syncRoles([$role]);

            AuditLog::log('role.assigned', auditable: $user, oldValues: [
                'role' => $oldRole,
            ], newValues: [
                'role' => $role->name,
                'call_sign' => $user->call_sign,
            ]);
        });

        $skippedSystem = $this->selectionIncludesSystemUser();
        $this->reset(['selectedUsers', 'selectAll', 'bulk_role_id']);
        $this->dispatchBulkResult($count, "Role assigned to {$count} ".str('user')->plural($count).'.', 'phosphor-check-circle', $skippedSystem);
    }

    public function bulkLockAccounts(): void
    {
        Gate::authorize('manage-users');

        if (empty($this->selectedUsers)) {
            return;
        }

        if (count($this->selectedUsers) > 50) {
            $this->dispatch('toast', title: 'Error', description: self::BULK_LIMIT_ERROR, icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        if (in_array(auth()->id(), $this->selectedUsers)) {
            $this->dispatch('toast', title: 'Error', description: 'You cannot lock your own account', icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        $userIds = User::whereIn('id', $this->selectedUsers)->pluck('id')->all();

        User::whereIn('id', $userIds)->update([
            'account_locked_at' => $this->bulkLockExpiry ?? now(),
            'approval_requested_at' => null,
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
        $this->dispatch('toast', title: 'Success', description: "{$count} ".str('account')->plural($count).' locked.', icon: 'phosphor-lock', css: 'alert-success');
    }

    public function bulkUnlockAccounts(): void
    {
        Gate::authorize('manage-users');

        if (empty($this->selectedUsers)) {
            return;
        }

        if (count($this->selectedUsers) > 50) {
            $this->dispatch('toast', title: 'Error', description: self::BULK_LIMIT_ERROR, icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        $userIds = User::whereIn('id', $this->selectedUsers)->pluck('id')->all();

        User::whereIn('id', $userIds)->update([
            'account_locked_at' => null,
            'approval_requested_at' => null,
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
        $this->dispatch('toast', title: 'Success', description: "{$count} ".str('account')->plural($count).' unlocked.', icon: 'phosphor-lock-open', css: 'alert-success');
    }

    public function openBulkDeleteModal(): void
    {
        Gate::authorize('manage-users');

        if (empty($this->selectedUsers)) {
            return;
        }

        $this->showBulkDeleteModal = true;
    }

    public function bulkDeleteUsers(): void
    {
        Gate::authorize('manage-users');

        if (empty($this->selectedUsers)) {
            return;
        }

        if (count($this->selectedUsers) > 50) {
            $this->dispatch('toast', title: 'Error', description: self::BULK_LIMIT_ERROR, icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        if (in_array(auth()->id(), $this->selectedUsers)) {
            $this->dispatch('toast', title: 'Error', description: 'You cannot delete your own account', icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        $userIds = User::whereIn('id', $this->selectedUsers)->excludeSystem()->pluck('id')->all();

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
        $skippedSystem = $this->selectionIncludesSystemUser();
        $this->showBulkDeleteModal = false;
        $this->reset(['selectedUsers', 'selectAll']);
        $this->dispatchBulkResult($count, "{$count} ".str('user')->plural($count).' deleted.', 'phosphor-trash', $skippedSystem);
    }

    /**
     * Whether the current bulk selection includes the protected SYSTEM account.
     */
    private function selectionIncludesSystemUser(): bool
    {
        return User::whereIn('id', $this->selectedUsers)
            ->where('call_sign', User::SYSTEM_CALL_SIGN)
            ->exists();
    }

    /**
     * Toast the outcome of a bulk action, warning instead of claiming success
     * when the protected SYSTEM account was the only user selected.
     */
    private function dispatchBulkResult(int $count, string $description, string $icon, bool $skippedSystem): void
    {
        if ($skippedSystem && $count === 0) {
            $this->dispatch('toast', title: 'Nothing changed', description: self::SYSTEM_ACCOUNT_PROTECTED, icon: 'phosphor-warning', css: 'alert-warning');

            return;
        }

        if ($skippedSystem) {
            $description .= ' '.self::SYSTEM_ACCOUNT_SKIPPED;
        }

        $this->dispatch('toast', title: 'Success', description: $description, icon: $icon, css: 'alert-success');
    }

    public function render()
    {
        return view('livewire.users.user-management')->layout('layouts.app', ['title' => 'Users']);
    }

    /**
     * Get friendly field names for validation messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'role_id' => 'role',
            'call_sign' => 'callsign',
            'newPassword' => 'temporary password',
        ];
    }
}
