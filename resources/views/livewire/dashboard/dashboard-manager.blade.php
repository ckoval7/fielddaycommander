<div>
    {{-- Dashboard Manager Modal --}}
    <x-modal wire:model="showModal" title="Manage Dashboards" class="backdrop-blur" separator>
        @if($showCreateForm)
            {{-- Create Dashboard Form --}}
            <div class="space-y-4">
                <x-header title="Create New Dashboard" subtitle="Start with a blank dashboard or copy an existing one" size="text-lg" />

                <form wire:submit="createDashboard">
                    <div class="space-y-4">
                        {{-- Title Input --}}
                        <x-input
                            label="Dashboard Title"
                            wire:model="newTitle"
                            placeholder="e.g., My Custom Dashboard"
                            icon="phosphor-stack"
                            required
                            maxlength="255"
                        />

                        {{-- Description Textarea --}}
                        <x-textarea
                            label="Description"
                            wire:model="newDescription"
                            placeholder="Optional description"
                            hint="Optional - describe what this dashboard is for"
                            rows="3"
                        />

                        {{-- Copy From Dropdown --}}
                        <x-select
                            label="Start From"
                            wire:model="copyFrom"
                            :options="$dashboards"
                            option-value="id"
                            option-label="title"
                            placeholder="Blank Dashboard"
                            hint="Leave blank to start fresh, or select a dashboard to copy"
                            icon="phosphor-copy"
                        />

                        {{-- Form Actions --}}
                        <div class="flex gap-3 justify-end pt-4">
                            <x-button
                                label="Cancel"
                                icon="phosphor-x"
                                class="btn-ghost"
                                wire:click="cancelCreate"
                            />
                            <x-button
                                label="Create Dashboard"
                                type="submit"
                                class="btn-primary"
                                icon="phosphor-plus"
                                spinner="createDashboard"
                            />
                        </div>
                    </div>
                </form>
            </div>
        @else
            {{-- Dashboard List --}}
            <div class="space-y-4">
                @if($dashboards->isEmpty())
                    {{-- Empty State --}}
                    <div class="text-center py-12">
                        <x-icon name="phosphor-stack" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
                        <p class="text-lg font-medium text-base-content mb-2">No dashboards yet</p>
                        <p class="text-sm text-base-content/60 mb-6">Create your first dashboard to get started</p>
                        <x-button
                            label="Create Dashboard"
                            icon="phosphor-plus"
                            class="btn-primary"
                            wire:click="openCreateForm"
                        />
                    </div>
                @else
                    {{-- Dashboard Items --}}
                    <div class="space-y-3">
                        @foreach($dashboards as $dashboard)
                            <x-card class="hover:shadow-md transition-shadow">
                                <div class="flex items-start justify-between gap-4">
                                    {{-- Dashboard Info --}}
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <h3 class="font-semibold text-base truncate">{{ $dashboard->title }}</h3>
                                            @if($dashboard->is_default)
                                                <span class="badge badge-primary badge-sm">Default</span>
                                            @endif
                                        </div>

                                        @if($dashboard->description)
                                            <p class="text-sm text-base-content/70 mb-2">{{ $dashboard->description }}</p>
                                        @endif

                                        <div class="flex items-center gap-4 text-xs text-base-content/50">
                                            <span class="flex items-center gap-1">
                                                <x-icon name="phosphor-calendar" class="w-3.5 h-3.5" />
                                                Updated {{ $dashboard->updated_at->diffForHumans() }}
                                            </span>
                                            <span class="flex items-center gap-1">
                                                <x-icon name="phosphor-squares-four" class="w-3.5 h-3.5" />
                                                {{ $dashboard->getVisibleWidgetCount() }} widgets
                                            </span>
                                        </div>
                                    </div>

                                    {{-- Actions --}}
                                    <div class="flex flex-col gap-2 shrink-0">
                                        @if(!$dashboard->is_default)
                                            <x-button
                                                label="Set as Default"
                                                icon="phosphor-star"
                                                class="btn-sm btn-ghost"
                                                wire:click="setDefault({{ $dashboard->id }})"
                                                spinner="setDefault"
                                            />
                                        @endif

                                        <x-button
                                            label="Duplicate"
                                            icon="phosphor-copy"
                                            class="btn-sm btn-ghost"
                                            wire:click="duplicateDashboard({{ $dashboard->id }})"
                                            spinner="duplicateDashboard"
                                        />

                                        <x-button
                                            label="Delete"
                                            icon="phosphor-trash"
                                            class="btn-sm btn-ghost text-error hover:bg-error/10"
                                            wire:click="confirmDelete({{ $dashboard->id }})"
                                        />
                                    </div>
                                </div>
                            </x-card>
                        @endforeach
                    </div>

                    {{-- Create New Button --}}
                    <div class="pt-4 border-t border-base-300">
                        <x-button
                            label="Create New Dashboard"
                            icon="phosphor-plus"
                            class="btn-primary w-full"
                            wire:click="openCreateForm"
                        />
                    </div>
                @endif
            </div>

            {{-- Modal Actions --}}
            <x-slot:actions>
                <x-button
                    label="Close"
                    @click="$wire.showModal = false"
                />
            </x-slot:actions>
        @endif
    </x-modal>

    {{-- Delete Confirmation Modal --}}
    <x-modal wire:model="showDeleteConfirmation" title="Delete Dashboard" class="backdrop-blur" persistent separator>
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="p-3 bg-error/10 rounded-lg">
                    <x-icon name="phosphor-warning" class="w-6 h-6 text-error" />
                </div>
                <div>
                    <p class="font-medium mb-2">Are you sure you want to delete this dashboard?</p>
                    <p class="text-sm text-base-content/70">This action cannot be undone. All widgets and settings for this dashboard will be permanently removed.</p>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button
                label="Cancel"
                class="btn-ghost"
                wire:click="cancelDelete"
            />
            <x-button
                label="Delete Dashboard"
                class="btn-error"
                icon="phosphor-trash"
                wire:click="deleteDashboard"
                spinner="deleteDashboard"
            />
        </x-slot:actions>
    </x-modal>
</div>
