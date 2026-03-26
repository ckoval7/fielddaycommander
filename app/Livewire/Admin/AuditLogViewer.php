<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogViewer extends Component
{
    use WithPagination;

    public array $filters = [
        'user_ids' => [],
        'action_types' => [],
        'date_from' => null,
        'date_to' => null,
        'ip_address' => null,
    ];

    public int $perPage = 25;

    public ?int $selectedLogId = null;

    public bool $showDetailModal = false;

    public function mount(): void
    {
        Gate::authorize('view-security-logs');
    }

    public function render()
    {
        $query = AuditLog::query()->with('user');

        // Apply filters using model scopes
        if (! empty($this->filters['user_ids'])) {
            $query->forUser($this->filters['user_ids']);
        }

        if (! empty($this->filters['action_types'])) {
            $query->forAction($this->filters['action_types']);
        }

        if ($this->filters['date_from'] || $this->filters['date_to']) {
            $query->dateRange($this->filters['date_from'], $this->filters['date_to']);
        }

        if ($this->filters['ip_address']) {
            $query->forIpAddress($this->filters['ip_address']);
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->paginate($this->perPage);

        return view('livewire.admin.audit-log-viewer', [
            'logs' => $logs,
            'users' => User::all(),
            'actionTypeGroups' => $this->getActionTypeGroups(),
        ])->layout('layouts.app');
    }

    public function clearFilters(): void
    {
        $this->filters = [
            'user_ids' => [],
            'action_types' => [],
            'date_from' => null,
            'date_to' => null,
            'ip_address' => null,
        ];
        $this->resetPage();
    }

    public function setDatePreset(string $preset): void
    {
        $now = Carbon::now();

        match ($preset) {
            '24h' => [
                $this->filters['date_from'] = $now->copy()->subDay()->format('Y-m-d'),
                $this->filters['date_to'] = $now->format('Y-m-d'),
            ],
            '7d' => [
                $this->filters['date_from'] = $now->copy()->subDays(7)->format('Y-m-d'),
                $this->filters['date_to'] = $now->format('Y-m-d'),
            ],
            '30d' => [
                $this->filters['date_from'] = $now->copy()->subDays(30)->format('Y-m-d'),
                $this->filters['date_to'] = $now->format('Y-m-d'),
            ],
            default => null,
        };

        $this->resetPage();
    }

    public function showDetails(int $logId): void
    {
        $this->selectedLogId = $logId;
        $this->showDetailModal = true;
    }

    public function closeDetails(): void
    {
        $this->showDetailModal = false;
        $this->selectedLogId = null;
    }

    public function exportCsv(): StreamedResponse
    {
        $query = AuditLog::query()->with('user');

        // Apply same filters as main view
        if (! empty($this->filters['user_ids'])) {
            $query->forUser($this->filters['user_ids']);
        }

        if (! empty($this->filters['action_types'])) {
            $query->forAction($this->filters['action_types']);
        }

        if ($this->filters['date_from'] || $this->filters['date_to']) {
            $query->dateRange($this->filters['date_from'], $this->filters['date_to']);
        }

        if ($this->filters['ip_address']) {
            $query->forIpAddress($this->filters['ip_address']);
        }

        $query->orderBy('created_at', 'desc');

        $filename = 'audit-logs-'.Carbon::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // Write headers
            fputcsv($handle, ['Time', 'User', 'Action', 'IP Address', 'User Agent']);

            // Stream logs
            $query->chunk(100, function ($logs) use ($handle) {
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->created_at->format('Y-m-d H:i:s'),
                        $log->user?->name ?? 'N/A',
                        $log->action,
                        $log->ip_address,
                        $log->user_agent,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    #[Computed]
    public function selectedLog(): ?AuditLog
    {
        if (! $this->selectedLogId) {
            return null;
        }

        return AuditLog::with('user')->find($this->selectedLogId);
    }

    protected function getActionTypeGroups(): array
    {
        $types = $this->getActionTypes();
        $groups = [];

        foreach ($types as $category => $actions) {
            foreach ($actions as $value => $label) {
                $groups[] = [
                    'value' => $value,
                    'label' => $label,
                    'group' => $category,
                ];
            }
        }

        return $groups;
    }

    protected function getActionTypes(): array
    {
        return [
            'Authentication' => [
                'user.login.success' => 'Logged in',
                'user.login.failed' => 'Failed login attempt',
                'user.login.2fa_failed' => 'Failed 2FA verification',
                'user.2fa.challenge' => '2FA challenge',
                'user.logout' => 'Logged out',
                'user.register' => 'User registered',
                'user.password.reset' => 'Password reset',
                'user.password.changed' => 'Password changed',
            ],
            'User Management' => [
                'user.created' => 'User created',
                'user.updated' => 'User updated',
                'user.deleted' => 'User deleted',
                'user.locked' => 'Account locked',
                'user.unlocked' => 'Account unlocked',
                'user.password.reset_by_admin' => 'Password reset by admin',
                'user.password.force_reset' => 'Force password reset required',
                'user.invitation.sent' => 'Invitation sent',
            ],
            'Profile' => [
                'user.profile.updated' => 'Profile updated',
                'user.2fa.enabled' => '2FA enabled',
                'user.2fa.disabled' => '2FA disabled',
            ],
            'Permissions' => [
                'role.created' => 'Role created',
                'role.updated' => 'Role updated',
                'role.deleted' => 'Role deleted',
                'role.assigned' => 'Role assigned',
            ],
            'Settings' => [
                'settings.updated' => 'Settings updated',
                'settings.branding.updated' => 'Branding updated',
            ],
            'Events' => [
                'event.created' => 'Event created',
                'event.updated' => 'Event updated',
                'event.deleted' => 'Event deleted',
                'event.activated' => 'Event activated',
                'event.deactivated' => 'Event deactivated',
            ],
            'Developer Tools' => [
                'developer.time_travel.set' => 'Time travel set',
                'developer.time_travel.clear' => 'Time travel cleared',
                'developer.database.full_reset' => 'Database full reset',
                'developer.database.selective_reset' => 'Database selective reset',
                'developer.snapshot.create' => 'Snapshot created',
                'developer.snapshot.restore' => 'Snapshot restored',
                'developer.snapshot.delete' => 'Snapshot deleted',
                'developer.test_users.initialize' => 'Test users initialized',
                'developer.test_users.clear' => 'Test users cleared',
                'developer.quick_action.seed_contacts' => 'Test contacts seeded',
                'developer.quick_action.fast_forward_event' => 'Fast-forwarded to event',
                'developer.quick_action.clear_caches' => 'Caches cleared',
            ],
        ];
    }
}
