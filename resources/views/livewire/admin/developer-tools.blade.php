<div>
    <x-slot:title>Developer Tools</x-slot:title>

    <div class="p-6 space-y-6">
        {{-- Header --}}
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold">Developer Tools</h1>
                <p class="text-base-content/60 mt-1">Administrative tools for development and testing</p>
            </div>
        </div>

        {{-- Warning Alert --}}
        <x-alert
            title="Destructive Operations"
            description="The tools on this page can permanently modify or delete data. Use with caution. All actions are logged for audit purposes."
            icon="phosphor-warning"
            class="alert-warning"
        />

        {{-- Time Travel Section --}}
        <x-card title="Time Travel" subtitle="Override the application's current time for testing" icon="phosphor-clock">
            <div class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <x-flatpickr
                        label="Date"
                        wire:model.live="fakeDate"
                        mode="date"
                        icon="phosphor-calendar"
                    />

                    <x-flatpickr
                        label="Time"
                        wire:model.live="fakeTime"
                        mode="time"
                        icon="phosphor-clock"
                    />

                    <div class="flex items-end pb-2">
                        <x-checkbox
                            label="Freeze time"
                            wire:model.live="timeFrozen"
                            hint="When checked, time stays fixed at the selected moment"
                        />
                    </div>

                    <div class="flex items-end gap-2">
                        <x-button
                            label="Set Time"
                            wire:click="setTime"
                            class="btn-primary"
                            icon="phosphor-play"
                            spinner="setTime"
                        />
                        <x-button
                            label="Clear"
                            wire:click="clearTime"
                            class="btn-ghost"
                            icon="phosphor-x"
                            spinner="clearTime"
                        />
                    </div>
                </div>

                {{-- Status Preview --}}
                @if($this->fieldDayStatusPreview)
                    <div class="bg-base-200 rounded-lg p-4">
                        <div class="flex items-center gap-3">
                            <x-icon name="phosphor-cell-signal-high" class="w-5 h-5 text-base-content/60" />
                            <span class="font-medium">Field Day Status Preview:</span>
                            <x-badge
                                :value="$this->fieldDayStatusPreview['status']"
                                :class="$this->fieldDayStatusPreview['class']"
                            />
                            <span class="text-sm text-base-content/60">
                                {{ $this->fieldDayStatusPreview['message'] }}
                            </span>
                        </div>
                    </div>
                @endif
            </div>
        </x-card>

        {{-- Database Tools Section --}}
        <x-card title="Database Tools" subtitle="Manage database state for development" icon="phosphor-database">
            <x-tabs wire:model="databaseTab">
                {{-- Full Reset Tab --}}
                <x-tab name="full-reset" label="Full Reset" icon="phosphor-arrow-clockwise">
                    <div class="py-4 space-y-4">
                        <x-alert
                            title="Full Database Reset"
                            description="This will drop all tables and re-run all migrations with seeders. All data will be lost and you will be logged out."
                            icon="phosphor-warning-circle"
                            class="alert-error"
                        />

                        <x-button
                            label="Reset Database"
                            wire:click="confirmFullReset"
                            class="btn-error"
                            icon="phosphor-trash"
                        />
                    </div>
                </x-tab>

                {{-- Selective Reset Tab --}}
                <x-tab name="selective-reset" label="Selective Reset" icon="phosphor-funnel">
                    <div class="py-4 space-y-4">
                        <p class="text-base-content/70">
                            Select which data categories to clear. This truncates the selected tables without affecting the rest of the database.
                        </p>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            @foreach($tableCategoryOptions as $option)
                                <label class="flex items-start gap-3 p-3 border border-base-300 rounded-lg cursor-pointer hover:bg-base-200 transition-colors">
                                    <input
                                        type="checkbox"
                                        wire:model="selectedTables"
                                        value="{{ $option['id'] }}"
                                        class="checkbox checkbox-sm mt-0.5"
                                    />
                                    <div class="min-w-0">
                                        <div class="font-medium">{{ $option['name'] }}</div>
                                        <div class="text-xs text-base-content/50 truncate">{{ $option['tables'] }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>

                        <x-button
                            label="Reset Selected Tables"
                            wire:click="confirmSelectiveReset"
                            class="btn-warning"
                            icon="phosphor-trash"
                            :disabled="empty($selectedTables)"
                        />
                    </div>
                </x-tab>

                {{-- Snapshots Tab --}}
                <x-tab name="snapshots" label="Snapshots" icon="phosphor-camera">
                    <div class="py-4 space-y-6">
                        {{-- Create Snapshot Form --}}
                        <div class="bg-base-200 rounded-lg p-4 space-y-4">
                            <h4 class="font-semibold">Create New Snapshot</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <x-input
                                    label="Snapshot Name"
                                    wire:model="snapshotName"
                                    placeholder="e.g., before-testing-feature"
                                    icon="phosphor-tag"
                                />
                                <x-input
                                    label="Description (optional)"
                                    wire:model="snapshotDescription"
                                    placeholder="Brief description of the snapshot"
                                    icon="phosphor-file-text"
                                />
                            </div>
                            <x-button
                                label="Create Snapshot"
                                wire:click="createSnapshot"
                                class="btn-primary"
                                icon="phosphor-camera"
                                spinner="createSnapshot"
                            />
                        </div>

                        {{-- Existing Snapshots --}}
                        <div>
                            <h4 class="font-semibold mb-3">Existing Snapshots</h4>

                            @if($snapshots->isEmpty())
                                <div class="text-center py-8 text-base-content/50">
                                    <x-icon name="phosphor-camera" class="w-12 h-12 mx-auto mb-2 opacity-50" />
                                    <p>No snapshots found</p>
                                    <p class="text-sm">Create a snapshot to save the current database state</p>
                                </div>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="table table-zebra w-full">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Description</th>
                                                <th>Created</th>
                                                <th>Size</th>
                                                <th class="text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($snapshots as $snapshot)
                                                <tr>
                                                    <td class="font-medium">{{ $snapshot['name'] }}</td>
                                                    <td class="text-base-content/60 max-w-xs truncate">
                                                        {{ $snapshot['description'] ?? '-' }}
                                                    </td>
                                                    <td class="text-sm">
                                                        {{ \Carbon\Carbon::parse($snapshot['created_at'])->format('M j, Y g:i A') }}
                                                    </td>
                                                    <td class="text-sm">{{ $snapshot['size'] }}</td>
                                                    <td class="text-right">
                                                        <div class="flex justify-end gap-1">
                                                            <x-button
                                                                icon="phosphor-arrow-u-up-left"
                                                                wire:click="confirmRestore('{{ $snapshot['filename'] }}')"
                                                                class="btn-sm btn-ghost"
                                                                tooltip="Restore"
                                                                spinner
                                                            />
                                                            <x-button
                                                                icon="phosphor-trash"
                                                                wire:click="deleteSnapshot('{{ $snapshot['filename'] }}')"
                                                                wire:confirm="Are you sure you want to delete this snapshot?"
                                                                class="btn-sm btn-ghost text-error"
                                                                tooltip="Delete"
                                                                spinner
                                                            />
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-tab>
            </x-tabs>
        </x-card>

        {{-- Quick Actions Section --}}
        <x-card title="Quick Actions" subtitle="Common development tasks" icon="phosphor-lightning">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                {{-- Seed Test Contacts --}}
                <div class="bg-base-200 rounded-lg p-4 space-y-3">
                    <div class="flex items-center gap-2">
                        <x-icon name="phosphor-user-plus" class="w-5 h-5 text-primary" />
                        <h4 class="font-semibold">Seed Test Contacts</h4>
                    </div>
                    <p class="text-sm text-base-content/60">
                        Create 50 random test contacts for the active event configuration.
                    </p>
                    <x-button
                        label="Seed 50 Contacts"
                        wire:click="seedTestContacts"
                        class="btn-primary btn-sm w-full"
                        icon="phosphor-plus"
                        spinner="seedTestContacts"
                    />
                </div>

                {{-- Fast Forward to Next Event --}}
                <div class="bg-base-200 rounded-lg p-4 space-y-3">
                    <div class="flex items-center gap-2">
                        <x-icon name="phosphor-fast-forward" class="w-5 h-5 text-success" />
                        <h4 class="font-semibold">Fast Forward Event</h4>
                    </div>
                    <p class="text-sm text-base-content/60">
                        Jump to the start time of the next upcoming event.
                    </p>
                    <x-button
                        label="Fast Forward"
                        wire:click="fastForwardToNextEvent"
                        class="btn-success btn-sm w-full"
                        icon="phosphor-fast-forward"
                        spinner="fastForwardToNextEvent"
                    />
                </div>

                {{-- Clear Caches --}}
                <div class="bg-base-200 rounded-lg p-4 space-y-3">
                    <div class="flex items-center gap-2">
                        <x-icon name="phosphor-arrow-clockwise" class="w-5 h-5 text-warning" />
                        <h4 class="font-semibold">Clear Caches</h4>
                    </div>
                    <p class="text-sm text-base-content/60">
                        Clear all application caches including config, routes, and views.
                    </p>
                    <x-button
                        label="Clear All Caches"
                        wire:click="clearCaches"
                        class="btn-warning btn-sm w-full"
                        icon="phosphor-trash"
                        spinner="clearCaches"
                    />
                </div>
            </div>
        </x-card>

        {{-- Test User Pool Section --}}
        <x-card title="Test User Pool" subtitle="Manage reusable test users" icon="phosphor-users">
            <div class="space-y-4">
                <div class="flex flex-col sm:flex-row gap-4 items-end">
                    <div class="flex-1">
                        <x-input
                            label="Number of test users"
                            wire:model="testUserCount"
                            type="number"
                            min="3"
                            max="50"
                            icon="phosphor-users-three"
                            hint="Range: 3-50 users"
                        />
                    </div>

                    <div class="flex gap-2 w-full sm:w-auto">
                        <x-button
                            label="Initialize Test Users"
                            wire:click="initializeTestUsers(testUserCount)"
                            class="btn-primary btn-sm flex-1 sm:flex-none"
                            icon="phosphor-user-plus"
                            spinner="initializeTestUsers"
                            :disabled="$this->testUserPoolExists()"
                        />
                        <x-button
                            label="Clear Test Users"
                            wire:click="confirmClearTestUsers"
                            class="btn-error btn-sm flex-1 sm:flex-none"
                            icon="phosphor-trash"
                            :disabled="!$this->testUserPoolExists()"
                        />
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    {{-- Full Reset Confirmation Modal --}}
    <x-modal wire:model="showResetModal" title="Confirm Full Database Reset" persistent class="backdrop-blur">
        <div class="space-y-4">
            <x-alert
                title="This action cannot be undone!"
                icon="phosphor-warning"
                class="alert-error"
            />
            <p>You are about to:</p>
            <ul class="list-disc list-inside text-base-content/70 space-y-1">
                <li>Drop all database tables</li>
                <li>Re-run all migrations</li>
                <li>Run all database seeders</li>
                <li>Destroy all existing data</li>
                <li>Log out all users</li>
            </ul>
            <p class="font-semibold">Are you absolutely sure you want to proceed?</p>
        </div>

        <x-slot:actions>
            <x-button
                label="Cancel"
                @click="$wire.showResetModal = false"
                class="btn-ghost"
            />
            <x-button
                label="Yes, Reset Everything"
                wire:click="fullReset"
                class="btn-error"
                icon="phosphor-trash"
                spinner="fullReset"
            />
        </x-slot:actions>
    </x-modal>

    {{-- Selective Reset Confirmation Modal --}}
    <x-modal wire:model="showSelectiveResetModal" title="Confirm Selective Reset" persistent class="backdrop-blur">
        <div class="space-y-4">
            <x-alert
                title="This will permanently delete data!"
                icon="phosphor-warning"
                class="alert-warning"
            />
            <p>You are about to clear the following categories:</p>
            <ul class="list-disc list-inside text-base-content/70 space-y-1">
                @foreach($selectedTables as $category)
                    @php
                        $option = collect($tableCategoryOptions)->firstWhere('id', $category);
                    @endphp
                    @if($option)
                        <li>{{ $option['name'] }} ({{ $option['tables'] }})</li>
                    @endif
                @endforeach
            </ul>
        </div>

        <x-slot:actions>
            <x-button
                label="Cancel"
                @click="$wire.showSelectiveResetModal = false"
                class="btn-ghost"
            />
            <x-button
                label="Yes, Clear Selected"
                wire:click="selectiveReset"
                class="btn-warning"
                icon="phosphor-trash"
                spinner="selectiveReset"
            />
        </x-slot:actions>
    </x-modal>

    {{-- Restore Snapshot Confirmation Modal --}}
    <x-modal wire:model="showRestoreModal" title="Confirm Snapshot Restore" persistent class="backdrop-blur">
        <div class="space-y-4">
            <x-alert
                title="This will replace all current data!"
                icon="phosphor-warning"
                class="alert-warning"
            />
            <p>You are about to restore the database from:</p>
            <p class="font-mono bg-base-200 p-2 rounded">{{ $selectedSnapshot }}</p>
            <p class="text-base-content/70">
                All current data will be replaced with the data from this snapshot.
                You will be logged out and redirected to the home page.
            </p>
        </div>

        <x-slot:actions>
            <x-button
                label="Cancel"
                @click="$wire.showRestoreModal = false"
                class="btn-ghost"
            />
            <x-button
                label="Yes, Restore Snapshot"
                wire:click="restoreSnapshot"
                class="btn-warning"
                icon="phosphor-arrow-u-up-left"
                spinner="restoreSnapshot"
            />
        </x-slot:actions>
    </x-modal>

    {{-- Clear Test Users Confirmation Modal --}}
    <x-modal wire:model="showClearTestUsersModal" title="Confirm Clear Test Users" persistent class="backdrop-blur">
        <div class="space-y-4">
            <x-alert
                title="This action will permanently delete data!"
                icon="phosphor-warning"
                class="alert-warning"
            />
            <p>You are about to delete:</p>
            <ul class="list-disc list-inside text-base-content/70 space-y-1">
                <li><strong>{{ $this->getTestUserPoolCount() }} test users</strong></li>
                <li>All contacts logged by these users</li>
                <li>All operating sessions created by these users</li>
                <li>All other data associated with these users</li>
            </ul>
            <p class="font-semibold">This cannot be undone. Are you sure?</p>
        </div>

        <x-slot:actions>
            <x-button
                label="Cancel"
                @click="$wire.showClearTestUsersModal = false"
                class="btn-ghost"
            />
            <x-button
                label="Yes, Delete All Test Users"
                wire:click="clearTestUsers"
                class="btn-error"
                icon="phosphor-trash"
                spinner="clearTestUsers"
            />
        </x-slot:actions>
    </x-modal>
</div>
