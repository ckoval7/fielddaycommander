<div>
    <x-slot:title>Import ADIF</x-slot:title>

    <div class="p-6">
        {{-- Step indicator --}}
        <div class="flex items-center gap-2 mb-6">
            @foreach ([1 => 'Upload', 2 => 'Map Fields', 3 => 'Review & Import'] as $num => $label)
                <div class="flex items-center gap-2">
                    <div @class([
                        'w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold',
                        'bg-primary text-primary-content' => $step === $num,
                        'bg-success text-success-content' => $step > $num,
                        'bg-base-300 text-base-content/50' => $step < $num,
                    ])>{{ $num }}</div>
                    <span @class(['font-semibold' => $step === $num, 'text-base-content/50' => $step < $num])>{{ $label }}</span>
                    @if ($num < 3)
                        <x-icon name="o-chevron-right" class="w-4 h-4 text-base-content/30" />
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Step 1: Upload --}}
        @if ($step === 1)
            <x-card title="Upload ADIF File">
                <form wire:submit="uploadFile">
                    <div class="space-y-4">
                        <div>
                            <input type="file" wire:model="adifFile" accept=".adi,.adif" class="file-input file-input-bordered w-full" />
                            @error('adifFile') <span class="text-error text-sm mt-1">{{ $message }}</span> @enderror
                        </div>

                        <div wire:loading wire:target="adifFile" class="text-sm text-base-content/70">
                            Uploading file...
                        </div>

                        <x-button type="submit" label="Parse & Continue" icon="o-arrow-right" class="btn-primary" spinner="uploadFile" />
                    </div>
                </form>
            </x-card>
        @endif

        {{-- Step 2: Field Mapping --}}
        @if ($step === 2)
            <div class="space-y-6">
                {{-- Upload summary --}}
                @if (!empty($uploadSummary))
                    <x-card title="File Summary">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div><span class="text-base-content/70 text-sm">Records:</span><br><strong>{{ $uploadSummary['total'] }}</strong></div>
                            <div><span class="text-base-content/70 text-sm">Date Range:</span><br><strong>{{ $uploadSummary['date_range'] }}</strong></div>
                            <div><span class="text-base-content/70 text-sm">Bands:</span><br><strong>{{ implode(', ', $uploadSummary['bands']) }}</strong></div>
                            <div><span class="text-base-content/70 text-sm">Modes:</span><br><strong>{{ implode(', ', $uploadSummary['modes']) }}</strong></div>
                        </div>
                    </x-card>
                @endif

                {{-- Unmapped bands --}}
                @if (!empty($mappingReport['unmapped_bands']))
                    <x-card title="Unmapped Bands">
                        <p class="text-sm text-base-content/70 mb-4">These band names from the ADIF file couldn't be matched automatically. Select the correct band for each.</p>
                        @foreach ($mappingReport['unmapped_bands'] as $bandName)
                            <div class="flex items-center gap-4 mb-3">
                                <span class="font-mono font-bold w-24">{{ $bandName }}</span>
                                <span class="text-base-content/50">&rarr;</span>
                                <select wire:model="bandMappings.{{ $bandName }}" class="select select-bordered select-sm w-48">
                                    <option value="">Select band...</option>
                                    @foreach ($this->availableBands as $band)
                                        <option value="{{ $band->id }}">{{ $band->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </x-card>
                @endif

                {{-- Unmapped modes --}}
                @if (!empty($mappingReport['unmapped_modes']))
                    <x-card title="Unmapped Modes">
                        <p class="text-sm text-base-content/70 mb-4">These modes from the ADIF file couldn't be matched automatically.</p>
                        @foreach ($mappingReport['unmapped_modes'] as $modeName)
                            <div class="flex items-center gap-4 mb-3">
                                <span class="font-mono font-bold w-24">{{ $modeName }}</span>
                                <span class="text-base-content/50">&rarr;</span>
                                <select wire:model="modeMappings.{{ $modeName }}" class="select select-bordered select-sm w-48">
                                    <option value="">Select mode...</option>
                                    @foreach ($this->availableModes as $mode)
                                        <option value="{{ $mode->id }}">{{ $mode->name }} ({{ $mode->category }})</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </x-card>
                @endif

                {{-- Unmapped sections --}}
                @if (!empty($mappingReport['unmapped_sections']))
                    <x-card title="Unmapped Sections">
                        <p class="text-sm text-base-content/70 mb-4">These ARRL section codes couldn't be matched.</p>
                        @foreach ($mappingReport['unmapped_sections'] as $sectionCode)
                            <div class="flex items-center gap-4 mb-3">
                                <span class="font-mono font-bold w-24">{{ $sectionCode }}</span>
                                <span class="text-base-content/50">&rarr;</span>
                                <select wire:model="sectionMappings.{{ $sectionCode }}" class="select select-bordered select-sm w-48">
                                    <option value="">Select section...</option>
                                    @foreach ($this->availableSections as $section)
                                        <option value="{{ $section->id }}">{{ $section->code }} - {{ $section->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </x-card>
                @endif

                {{-- Unmapped stations --}}
                @if (!empty($mappingReport['unmapped_stations']))
                    <x-card title="Unmapped Stations">
                        <p class="text-sm text-base-content/70 mb-4">These station identifiers couldn't be matched to event stations.</p>
                        @foreach ($mappingReport['unmapped_stations'] as $stationName)
                            <div class="flex items-center gap-4 mb-3">
                                <span class="font-mono font-bold w-48">{{ $stationName }}</span>
                                <span class="text-base-content/50">&rarr;</span>
                                <select wire:model="stationMappings.{{ $stationName }}" class="select select-bordered select-sm w-48">
                                    <option value="">Select station...</option>
                                    @foreach ($this->availableStations as $station)
                                        <option value="{{ $station->id }}">{{ $station->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </x-card>
                @endif

                {{-- Unmapped operators --}}
                @if (!empty($mappingReport['unmapped_operators']))
                    <x-card title="Unmapped Operators">
                        <p class="text-sm text-base-content/70 mb-4">These operator callsigns couldn't be matched to users.</p>
                        @foreach ($mappingReport['unmapped_operators'] as $opCall)
                            <div class="flex items-center gap-4 mb-3">
                                <span class="font-mono font-bold w-24">{{ $opCall }}</span>
                                <span class="text-base-content/50">&rarr;</span>
                                <select wire:model="operatorMappings.{{ $opCall }}" class="select select-bordered select-sm w-48">
                                    <option value="">Select operator...</option>
                                    @foreach ($this->availableOperators as $op)
                                        <option value="{{ $op->id }}">{{ $op->call_sign }} - {{ $op->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </x-card>
                @endif

                {{-- Inconsistencies --}}
                @if (!empty($inconsistencies))
                    <x-card title="Class/Section Inconsistencies">
                        <p class="text-sm text-base-content/70 mb-4">These callsigns have conflicting class or section values across QSOs. Pick the correct value.</p>
                        @foreach ($inconsistencies as $callsign => $issues)
                            <div class="mb-4 p-3 bg-base-200 rounded-lg">
                                <span class="font-mono font-bold text-lg">{{ $callsign }}</span>
                                @if (isset($issues['exchange_class']))
                                    <div class="flex items-center gap-4 mt-2">
                                        <span class="text-sm text-base-content/70 w-24">Class:</span>
                                        @foreach ($issues['exchange_class'] as $classVal)
                                            <label class="flex items-center gap-1">
                                                <input type="radio" wire:model="resolutions.{{ $callsign }}.exchange_class" value="{{ $classVal }}" class="radio radio-sm radio-primary" />
                                                <span class="font-mono">{{ $classVal }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                                @if (isset($issues['section_code']))
                                    <div class="flex items-center gap-4 mt-2">
                                        <span class="text-sm text-base-content/70 w-24">Section:</span>
                                        @foreach ($issues['section_code'] as $secVal)
                                            <label class="flex items-center gap-1">
                                                <input type="radio" wire:model="resolutions.{{ $callsign }}.section_code" value="{{ $secVal }}" class="radio radio-sm radio-primary" />
                                                <span class="font-mono">{{ $secVal }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </x-card>
                @endif

                @error('mapping')
                    <x-alert icon="o-exclamation-triangle" class="alert-error">{{ $message }}</x-alert>
                @enderror

                <x-button wire:click="applyMappingsAndContinue" label="Continue to Review" icon="o-arrow-right" class="btn-primary" spinner="applyMappingsAndContinue" />
            </div>
        @endif

        {{-- Step 3: Review & Import --}}
        @if ($step === 3)
            <div class="space-y-6">
                {{-- Match summary --}}
                <x-card title="Import Summary">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                        <div class="p-4 bg-success/10 rounded-lg">
                            <div class="text-3xl font-bold text-success">{{ $matchSummary['new'] ?? 0 }}</div>
                            <div class="text-sm text-base-content/70">New Contacts</div>
                        </div>
                        <div class="p-4 bg-info/10 rounded-lg">
                            <div class="text-3xl font-bold text-info">{{ $matchSummary['merge'] ?? 0 }}</div>
                            <div class="text-sm text-base-content/70">Merge Candidates</div>
                        </div>
                        <div class="p-4 bg-warning/10 rounded-lg">
                            <div class="text-3xl font-bold text-warning">{{ $matchSummary['skip'] ?? 0 }}</div>
                            <div class="text-sm text-base-content/70">Skipped</div>
                        </div>
                        <div class="p-4 bg-error/10 rounded-lg">
                            <div class="text-3xl font-bold text-error">{{ $matchSummary['invalid'] ?? 0 }}</div>
                            <div class="text-sm text-base-content/70">Invalid</div>
                        </div>
                    </div>
                </x-card>

                {{-- Records table --}}
                <x-card title="Records">
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Callsign</th>
                                    <th>QSO Time</th>
                                    <th>Band</th>
                                    <th>Mode</th>
                                    <th>Section</th>
                                    <th>Class</th>
                                    <th>Operator</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->importRecords as $record)
                                    <tr wire:key="record-{{ $record->id }}" @class(['bg-error/5' => $record->status === \App\Enums\AdifRecordStatus::Invalid])>
                                        <td>
                                            @switch($record->status->value)
                                                @case('ready')
                                                    <span class="badge badge-success badge-sm">New</span>
                                                    @break
                                                @case('duplicate_match')
                                                    <span class="badge badge-info badge-sm">Merge</span>
                                                    @break
                                                @case('skipped')
                                                    <span class="badge badge-warning badge-sm">Skip</span>
                                                    @break
                                                @case('invalid')
                                                    <span class="badge badge-error badge-sm" title="{{ $record->notes }}">Invalid</span>
                                                    @break
                                                @default
                                                    <span class="badge badge-ghost badge-sm">{{ $record->status->value }}</span>
                                            @endswitch
                                        </td>
                                        <td class="font-mono font-bold">{{ $record->callsign }}</td>
                                        <td>{{ $record->qso_time?->format('Y-m-d H:i') }}</td>
                                        <td>{{ $record->band_name }}</td>
                                        <td>{{ $record->mode_name }}</td>
                                        <td>{{ $record->section_code }}</td>
                                        <td>{{ $record->exchange_class }}</td>
                                        <td class="font-mono">{{ $record->operator_callsign }}</td>
                                        <td>
                                            @if ($record->status === \App\Enums\AdifRecordStatus::Invalid)
                                                <x-button wire:click="toggleSkip({{ $record->id }})" label="Skip" icon="o-x-mark" class="btn-error btn-xs" spinner />
                                            @elseif ($record->status === \App\Enums\AdifRecordStatus::Skipped)
                                                <x-button wire:click="toggleSkip({{ $record->id }})" label="Include" icon="o-arrow-uturn-left" class="btn-ghost btn-xs" spinner />
                                            @elseif ($record->status === \App\Enums\AdifRecordStatus::Ready || $record->status === \App\Enums\AdifRecordStatus::DuplicateMatch)
                                                <x-button wire:click="toggleSkip({{ $record->id }})" label="Skip" icon="o-x-mark" class="btn-ghost btn-xs" spinner />
                                            @endif
                                        </td>
                                    </tr>
                                    @if ($record->status === \App\Enums\AdifRecordStatus::Invalid && $record->notes)
                                        <tr wire:key="record-notes-{{ $record->id }}" class="bg-error/5">
                                            <td colspan="9" class="text-error text-sm pt-0">{{ $record->notes }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-card>

                @if ($importStatus === 'completed')
                    <x-alert icon="o-check-circle" class="alert-success">
                        Import completed successfully!
                        {{ $this->currentImport?->imported_records ?? 0 }} imported,
                        {{ $this->currentImport?->merged_records ?? 0 }} merged,
                        {{ $this->currentImport?->skipped_records ?? 0 }} skipped.
                    </x-alert>
                @elseif ($importStatus === 'failed')
                    <x-alert icon="o-exclamation-triangle" class="alert-error">
                        Import failed. Please check the logs and try again.
                    </x-alert>
                @else
                    @if ($this->hasInvalidRecords)
                        <x-alert icon="o-exclamation-triangle" class="alert-warning mb-4">
                            {{ $matchSummary['invalid'] ?? 0 }} record(s) have validation errors. Skip or resolve them before importing.
                        </x-alert>
                    @endif
                    <x-button wire:click="executeImport" label="Import Contacts" icon="o-arrow-down-tray" class="btn-primary" spinner="executeImport" :disabled="$this->hasInvalidRecords" />
                @endif
            </div>
        @endif
    </div>
</div>
