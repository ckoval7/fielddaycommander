<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Services\DatabaseSnapshotService;
use App\Services\DeveloperClockService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Mary\Traits\Toast;
use RuntimeException;

class DeveloperTools extends Component
{
    use Toast;

    // Time Travel Properties
    public ?string $fakeDate = null;

    public ?string $fakeTime = null;

    public bool $timeFrozen = true;

    // Snapshot Properties
    public string $snapshotName = '';

    public string $snapshotDescription = '';

    // Selective Reset Properties
    public array $selectedTables = [];

    // Modal Properties
    public bool $showResetModal = false;

    public bool $showRestoreModal = false;

    public bool $showSelectiveResetModal = false;

    public ?string $selectedSnapshot = null;

    // Tab tracking
    public string $databaseTab = 'full-reset';

    /**
     * Table categories for selective reset.
     *
     * @var array<string, array<int, string>>
     */
    protected array $tableCategories = [
        'contacts' => ['contacts', 'operating_sessions'],
        'guestbook' => ['guestbook_entries'],
        'equipment' => ['equipment_event', 'station_equipment'],
        'event_config' => ['event_configurations', 'event_bonuses'],
        'audit' => ['audit_logs'],
        'images' => ['images'],
    ];

    public function mount(): void
    {
        Gate::authorize('manage-settings');

        $this->loadCurrentFakeTime();
    }

    public function render()
    {
        return view('livewire.admin.developer-tools', [
            'snapshots' => $this->snapshots,
            'tableCategoryOptions' => $this->getTableCategoryOptions(),
        ]);
    }

    /**
     * Load current fake time from the DeveloperClockService.
     */
    protected function loadCurrentFakeTime(): void
    {
        $clockService = app(DeveloperClockService::class);

        $fakeTime = $clockService->getFakeTime();
        if ($fakeTime !== null) {
            $this->fakeDate = $fakeTime->format('Y-m-d');
            $this->fakeTime = $fakeTime->format('H:i');
            $this->timeFrozen = $clockService->isFrozen();
        } else {
            $this->fakeDate = null;
            $this->fakeTime = null;
            $this->timeFrozen = true;
        }
    }

    /**
     * Get all snapshots from the DatabaseSnapshotService.
     */
    #[Computed]
    public function snapshots(): Collection
    {
        $snapshotService = app(DatabaseSnapshotService::class);

        return $snapshotService->listSnapshots();
    }

    /**
     * Get Field Day status preview based on the selected fake time.
     */
    #[Computed]
    public function fieldDayStatusPreview(): ?array
    {
        if ($this->fakeDate === null) {
            return null;
        }

        try {
            $selectedTime = $this->fakeTime
                ? Carbon::parse("{$this->fakeDate} {$this->fakeTime}")
                : Carbon::parse($this->fakeDate)->startOfDay();

            // Find the active event configuration
            $activeEvent = EventConfiguration::query()
                ->where('is_active', true)
                ->with('event')
                ->first();

            if ($activeEvent === null || $activeEvent->event === null) {
                return [
                    'status' => 'No Active Event',
                    'message' => 'No event is currently active.',
                    'class' => 'badge-warning',
                ];
            }

            $event = $activeEvent->event;
            $startTime = Carbon::parse($event->start_time);
            $endTime = Carbon::parse($event->end_time);

            if ($selectedTime->lt($startTime)) {
                $diff = $selectedTime->diff($startTime);

                return [
                    'status' => 'UPCOMING',
                    'message' => "Event starts in {$diff->days}d {$diff->h}h {$diff->i}m",
                    'class' => 'badge-info',
                ];
            } elseif ($selectedTime->between($startTime, $endTime)) {
                $elapsed = $startTime->diff($selectedTime);
                $remaining = $selectedTime->diff($endTime);

                return [
                    'status' => 'IN PROGRESS',
                    'message' => "Elapsed: {$elapsed->h}h {$elapsed->i}m | Remaining: {$remaining->days}d {$remaining->h}h {$remaining->i}m",
                    'class' => 'badge-success',
                ];
            } else {
                $endedAgo = $endTime->diff($selectedTime);

                return [
                    'status' => 'ENDED',
                    'message' => "Event ended {$endedAgo->days}d {$endedAgo->h}h ago",
                    'class' => 'badge-error',
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'Error',
                'message' => 'Unable to calculate status',
                'class' => 'badge-error',
            ];
        }
    }

    /**
     * Get table category options for the selective reset form.
     *
     * @return array<int, array{id: string, name: string, tables: string}>
     */
    protected function getTableCategoryOptions(): array
    {
        return [
            ['id' => 'contacts', 'name' => 'Contacts & Sessions', 'tables' => 'contacts, operating_sessions'],
            ['id' => 'guestbook', 'name' => 'Guestbook Entries', 'tables' => 'guestbook_entries'],
            ['id' => 'equipment', 'name' => 'Equipment Assignments', 'tables' => 'equipment_event, station_equipment'],
            ['id' => 'event_config', 'name' => 'Event Configurations', 'tables' => 'event_configurations, event_bonuses'],
            ['id' => 'audit', 'name' => 'Audit Logs', 'tables' => 'audit_logs'],
            ['id' => 'images', 'name' => 'Images', 'tables' => 'images'],
        ];
    }

    // =====================
    // Time Travel Methods
    // =====================

    /**
     * Set the fake time using the DeveloperClockService.
     */
    public function setTime(): void
    {
        if ($this->fakeDate === null) {
            $this->error('Please select a date.');

            return;
        }

        try {
            $clockService = app(DeveloperClockService::class);

            $dateTimeString = $this->fakeTime
                ? "{$this->fakeDate} {$this->fakeTime}"
                : "{$this->fakeDate} 00:00";

            $time = Carbon::parse($dateTimeString);

            $clockService->setFakeTime($time, $this->timeFrozen);

            AuditLog::log(
                action: 'developer.time_travel.set',
                userId: auth()->id(),
                newValues: [
                    'fake_time' => $time->toIso8601String(),
                    'frozen' => $this->timeFrozen,
                ]
            );

            $this->success(
                "Time set to {$time->format('M j, Y g:i A')}",
                $this->timeFrozen ? 'Time is frozen' : 'Time is flowing'
            );
        } catch (\Exception $e) {
            $this->error('Failed to set time', $e->getMessage());
        }
    }

    /**
     * Clear the fake time override.
     */
    public function clearTime(): void
    {
        try {
            $clockService = app(DeveloperClockService::class);
            $clockService->clearFakeTime();

            $this->fakeDate = null;
            $this->fakeTime = null;
            $this->timeFrozen = true;

            AuditLog::log(
                action: 'developer.time_travel.clear',
                userId: auth()->id()
            );

            $this->success('Time override cleared', 'Using real system time');
        } catch (\Exception $e) {
            $this->error('Failed to clear time', $e->getMessage());
        }
    }

    // =====================
    // Database Methods
    // =====================

    /**
     * Show confirmation modal for full database reset.
     */
    public function confirmFullReset(): void
    {
        $this->showResetModal = true;
    }

    /**
     * Perform full database reset (migrate:fresh --seed).
     */
    public function fullReset(): void
    {
        try {
            AuditLog::log(
                action: 'developer.database.full_reset',
                userId: auth()->id(),
                isCritical: true
            );

            Artisan::call('migrate:fresh', [
                '--seed' => true,
                '--force' => true,
            ]);

            $this->showResetModal = false;

            // Redirect to home since user session may be invalid
            $this->success('Database reset complete', redirectTo: '/');
        } catch (\Exception $e) {
            $this->error('Database reset failed', $e->getMessage());
        }
    }

    /**
     * Show confirmation modal for selective reset.
     */
    public function confirmSelectiveReset(): void
    {
        if (empty($this->selectedTables)) {
            $this->error('Please select at least one category to reset.');

            return;
        }

        $this->showSelectiveResetModal = true;
    }

    /**
     * Perform selective table reset.
     */
    public function selectiveReset(): void
    {
        if (empty($this->selectedTables)) {
            $this->error('No tables selected for reset.');

            return;
        }

        try {
            $tablesToTruncate = [];

            foreach ($this->selectedTables as $category) {
                if (isset($this->tableCategories[$category])) {
                    $tablesToTruncate = array_merge(
                        $tablesToTruncate,
                        $this->tableCategories[$category]
                    );
                }
            }

            $tablesToTruncate = array_unique($tablesToTruncate);

            // Disable foreign key checks temporarily
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            foreach ($tablesToTruncate as $table) {
                DB::table($table)->truncate();
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            AuditLog::log(
                action: 'developer.database.selective_reset',
                userId: auth()->id(),
                newValues: [
                    'categories' => $this->selectedTables,
                    'tables' => $tablesToTruncate,
                ],
                isCritical: true
            );

            $this->selectedTables = [];
            $this->showSelectiveResetModal = false;

            $this->success(
                'Tables reset successfully',
                'Cleared: '.implode(', ', $tablesToTruncate)
            );
        } catch (\Exception $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->error('Selective reset failed', $e->getMessage());
        }
    }

    /**
     * Create a new database snapshot.
     */
    public function createSnapshot(): void
    {
        if (empty($this->snapshotName)) {
            $this->error('Please enter a snapshot name.');

            return;
        }

        try {
            $snapshotService = app(DatabaseSnapshotService::class);

            $filename = $snapshotService->createSnapshot(
                $this->snapshotName,
                $this->snapshotDescription ?: null
            );

            AuditLog::log(
                action: 'developer.snapshot.create',
                userId: auth()->id(),
                newValues: [
                    'filename' => $filename,
                    'name' => $this->snapshotName,
                    'description' => $this->snapshotDescription,
                ]
            );

            $this->snapshotName = '';
            $this->snapshotDescription = '';

            // Clear the computed property cache
            unset($this->snapshots);

            $this->success('Snapshot created', $filename);
        } catch (RuntimeException $e) {
            $this->error('Snapshot creation failed', $e->getMessage());
        }
    }

    /**
     * Show confirmation modal for snapshot restore.
     */
    public function confirmRestore(string $filename): void
    {
        $this->selectedSnapshot = $filename;
        $this->showRestoreModal = true;
    }

    /**
     * Restore a database snapshot.
     */
    public function restoreSnapshot(): void
    {
        if ($this->selectedSnapshot === null) {
            $this->error('No snapshot selected.');

            return;
        }

        try {
            $snapshotService = app(DatabaseSnapshotService::class);
            $snapshotService->restoreSnapshot($this->selectedSnapshot);

            AuditLog::log(
                action: 'developer.snapshot.restore',
                userId: auth()->id(),
                newValues: ['filename' => $this->selectedSnapshot],
                isCritical: true
            );

            $this->showRestoreModal = false;
            $this->selectedSnapshot = null;

            $this->success('Snapshot restored', redirectTo: '/');
        } catch (RuntimeException $e) {
            $this->error('Snapshot restore failed', $e->getMessage());
        }
    }

    /**
     * Delete a database snapshot.
     */
    public function deleteSnapshot(string $filename): void
    {
        try {
            $snapshotService = app(DatabaseSnapshotService::class);
            $snapshotService->deleteSnapshot($filename);

            AuditLog::log(
                action: 'developer.snapshot.delete',
                userId: auth()->id(),
                newValues: ['filename' => $filename]
            );

            // Clear the computed property cache
            unset($this->snapshots);

            $this->success('Snapshot deleted', $filename);
        } catch (\Exception $e) {
            $this->error('Failed to delete snapshot', $e->getMessage());
        }
    }

    // =====================
    // Quick Actions
    // =====================

    /**
     * Seed test contacts for the active event.
     */
    public function seedTestContacts(): void
    {
        try {
            $activeEvent = EventConfiguration::query()
                ->where('is_active', true)
                ->first();

            if ($activeEvent === null) {
                $this->error('No active event', 'Please activate an event first.');

                return;
            }

            // Create 50 test contacts
            Contact::factory()
                ->count(50)
                ->create([
                    'event_configuration_id' => $activeEvent->id,
                ]);

            AuditLog::log(
                action: 'developer.quick_action.seed_contacts',
                userId: auth()->id(),
                newValues: [
                    'count' => 50,
                    'event_configuration_id' => $activeEvent->id,
                ]
            );

            $this->success('Test contacts created', '50 contacts added to the active event');
        } catch (\Exception $e) {
            $this->error('Failed to seed contacts', $e->getMessage());
        }
    }

    /**
     * Trigger the event activation command.
     */
    public function triggerEventActivation(): void
    {
        try {
            Artisan::call('events:activate-by-date');
            $output = Artisan::output();

            AuditLog::log(
                action: 'developer.quick_action.event_activation',
                userId: auth()->id(),
                newValues: ['output' => $output]
            );

            $this->success('Event activation triggered', trim($output) ?: 'Command executed');
        } catch (\Exception $e) {
            $this->error('Event activation failed', $e->getMessage());
        }
    }

    /**
     * Clear all application caches.
     */
    public function clearCaches(): void
    {
        try {
            Artisan::call('optimize:clear');
            $output = Artisan::output();

            AuditLog::log(
                action: 'developer.quick_action.clear_caches',
                userId: auth()->id()
            );

            $this->success('Caches cleared', trim($output) ?: 'All caches have been cleared');
        } catch (\Exception $e) {
            $this->error('Failed to clear caches', $e->getMessage());
        }
    }
}
