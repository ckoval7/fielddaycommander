<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\NetLog;

use App\Enums\NetPriority;
use App\Events\NetLogChanged;
use App\Models\Band;
use App\Models\Event;
use App\Models\Mode;
use App\Models\Net;
use App\Models\NetLogEntry;
use App\Services\NetLogExchangeParser;
use App\Services\TacticalCallsignResolver;
use App\Support\BroadcastChannels;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Net control's log for a directed net, in the same chat-style window as
 * the contact log. Each line is "[called station] [calling station] [message]".
 *
 * An event can run several nets at once. Each screen works one net, picked
 * with the switcher and kept in the URL, so a station computer stays on its
 * net. Every net control screen at the event sees changes live over Reverb.
 */
class NetLog extends Component
{
    /**
     * Entries shown in the log window. The full log is on the ICS-309.
     */
    private const VISIBLE_ENTRIES = 100;

    private const MAX_SUGGESTIONS = 5;

    public Event $event;

    /**
     * The net this screen is logging.
     */
    #[Url(as: 'net')]
    public ?int $netId = null;

    public string $exchangeInput = '';

    /**
     * Tactical name suggestions for the station being typed.
     *
     * @var list<array{exchange: string, worked_on: string}>
     */
    public array $suggestions = [];

    public string $priority = 'routine';

    /**
     * Why the last line couldn't be logged. Kept out of the validation error
     * bag, like the contact log's parse error, so the app-wide error summary
     * doesn't repeat it.
     */
    public ?string $parseError = null;

    public bool $showNetForm = false;

    /**
     * The net being edited in the net form, or null when starting one.
     */
    public ?int $editingNetId = null;

    public string $netName = '';

    public ?string $netFrequency = null;

    public ?int $netBandId = null;

    public ?int $netModeId = null;

    public ?string $netControlStation = null;

    public function mount(Event $event): void
    {
        abort_if($event->eventConfiguration === null, 404);

        $this->event = $event;
        $this->netId = $this->resolveNetId($this->netId);
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            BroadcastChannels::privateListener(
                BroadcastChannels::event($this->event->id),
                '.'.BroadcastChannels::NET_LOG_CHANGED,
            ) => 'refreshEntries',
        ];
    }

    /**
     * The newest entries, oldest first, so the newest sits right above the
     * input. Recently deleted entries stay visible with an Undo.
     *
     * @return Collection<int, NetLogEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        if ($this->currentNet === null) {
            return new Collection;
        }

        return NetLogEntry::withTrashed()
            ->where('net_id', $this->currentNet->id)
            ->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('deleted_at', '>=', now()->subMinutes(10)))
            ->with('user')
            ->orderByDesc('logged_at')
            ->orderByDesc('id')
            ->limit(self::VISIBLE_ENTRIES)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * The event's nets, for the switcher.
     *
     * @return Collection<int, Net>
     */
    #[Computed]
    public function nets(): Collection
    {
        return Net::forEvent($this->event->eventConfiguration->id)
            ->with(['band', 'mode'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function currentNet(): ?Net
    {
        return $this->netId === null ? null : $this->nets->firstWhere('id', $this->netId);
    }

    /**
     * Tactical names in use at the event, plus this net's net control
     * station, for parsing and suggestions.
     *
     * @return list<string>
     */
    #[Computed]
    public function tacticalNames(): array
    {
        return app(TacticalCallsignResolver::class)->tacticalNames($this->event->eventConfiguration)
            ->push($this->currentNet?->net_control_station)
            ->filter(fn (?string $name) => $name !== null && $name !== '')
            ->unique(fn (string $name) => mb_strtolower($name))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Band>
     */
    #[Computed]
    public function bands(): Collection
    {
        return Band::ordered()->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Mode>
     */
    #[Computed]
    public function modes(): Collection
    {
        return Mode::orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    #[Computed]
    public function priorityOptions(): array
    {
        return collect(NetPriority::cases())
            ->map(fn (NetPriority $priority) => ['id' => $priority->value, 'name' => $priority->label()])
            ->all();
    }

    public function refreshEntries(): void
    {
        unset($this->nets, $this->currentNet, $this->entries, $this->tacticalNames);
        $this->netId = $this->resolveNetId($this->netId);
    }

    /**
     * Switch this screen to another net.
     */
    public function updatedNetId(): void
    {
        unset($this->currentNet, $this->entries, $this->tacticalNames);
        $this->netId = $this->resolveNetId($this->netId);
        $this->reset('exchangeInput', 'suggestions', 'parseError', 'priority');
    }

    /**
     * Open the net form to start a net, or to edit one.
     */
    public function openNetForm(?int $netId = null): void
    {
        $net = $netId === null ? null : $this->findEventNet($netId);

        abort_unless($net === null ? auth()->user()?->can('create', Net::class) : auth()->user()?->can('update', $net), 403);

        $this->resetValidation();
        $this->editingNetId = $net?->id;
        $this->netName = $net->name ?? '';
        $this->netFrequency = $net?->frequency;
        $this->netBandId = $net?->band_id;
        $this->netModeId = $net?->mode_id;
        $this->netControlStation = $net?->net_control_station;
        $this->showNetForm = true;
    }

    public function saveNet(): void
    {
        $net = $this->editingNetId === null ? null : $this->findEventNet($this->editingNetId);

        abort_unless($net === null ? auth()->user()?->can('create', Net::class) : auth()->user()?->can('update', $net), 403);

        $validated = $this->validate([
            'netName' => ['required', 'string', 'max:100'],
            'netFrequency' => ['nullable', 'string', 'max:30'],
            'netBandId' => ['nullable', 'integer', 'exists:bands,id'],
            'netModeId' => ['nullable', 'integer', 'exists:modes,id'],
            'netControlStation' => ['nullable', 'string', 'max:50'],
        ], attributes: [
            'netName' => 'net name',
            'netFrequency' => 'frequency',
            'netBandId' => 'band',
            'netModeId' => 'mode',
            'netControlStation' => 'net control station',
        ]);

        $attributes = [
            'name' => trim($validated['netName']),
            'frequency' => $this->blankToNull($validated['netFrequency']),
            'band_id' => $validated['netBandId'],
            'mode_id' => $validated['netModeId'],
            'net_control_station' => $this->blankToNull($validated['netControlStation']),
        ];

        if ($net === null) {
            $net = Net::create($attributes + [
                'event_configuration_id' => $this->event->eventConfiguration->id,
                'user_id' => auth()->id(),
            ]);
        } else {
            $net->update($attributes);
        }

        $this->showNetForm = false;
        $this->netId = $net->id;
        unset($this->nets, $this->currentNet, $this->entries, $this->tacticalNames);
        NetLogChanged::dispatch($this->event->id);

        $this->dispatch('toast', title: $this->editingNetId === null ? 'Net started' : 'Net updated', type: 'success');
    }

    /**
     * Delete a net that has nothing logged on it, such as one planned but
     * never run.
     */
    public function deleteNet(int $netId): void
    {
        $net = $this->findEventNet($netId);

        if (! auth()->user()?->can('delete', $net)) {
            $this->dispatch('toast', title: 'Only an empty net you started can be deleted', type: 'error');

            return;
        }

        $net->delete();
        $this->showNetForm = false;
        unset($this->nets, $this->currentNet, $this->entries, $this->tacticalNames);
        $this->netId = $this->resolveNetId(null);
        NetLogChanged::dispatch($this->event->id);
    }

    public function updatedExchangeInput(): void
    {
        $this->parseError = null;
        $this->suggestions = $this->buildSuggestions($this->exchangeInput);
    }

    public function selectSuggestion(string $exchange): void
    {
        $this->exchangeInput = $exchange;
        $this->suggestions = [];
        $this->dispatch('suggestion-selected');
    }

    public function clearInput(): void
    {
        $this->reset('exchangeInput', 'suggestions', 'parseError');
    }

    public function setPriority(string $priority): void
    {
        $this->priority = NetPriority::tryFrom($priority)?->value ?? NetPriority::Routine->value;
    }

    public function logEntry(?string $line = null): void
    {
        abort_unless(auth()->user()?->can('create', NetLogEntry::class), 403);

        if ($this->currentNet === null) {
            $this->parseError = 'Start a net before logging traffic.';

            return;
        }

        $parsed = $this->parseLine($line ?? $this->exchangeInput);

        if ($parsed === null) {
            return;
        }

        $entry = NetLogEntry::create([
            'event_configuration_id' => $this->event->eventConfiguration->id,
            'net_id' => $this->currentNet->id,
            'user_id' => auth()->id(),
            'logged_at' => appNow(),
            'from_station' => $parsed['from'],
            'to_station' => $parsed['to'],
            'from_callsign' => $this->resolveCallsign($parsed['from']),
            'to_callsign' => $this->resolveCallsign($parsed['to']),
            'message' => $parsed['message'],
            'priority' => $parsed['priority'] ?? NetPriority::from($this->priority),
        ]);

        $this->broadcastChange($entry);
        $this->reset('exchangeInput', 'suggestions', 'priority');
        unset($this->entries);

        $this->dispatch('net-log-entry-logged');
    }

    /**
     * Save a recalled entry from its edited line.
     */
    public function updateEntry(int $entryId, string $line): void
    {
        $entry = $this->findEventEntry($entryId);

        if (! auth()->user()?->can('update', $entry)) {
            $this->dispatch('toast', title: 'You can only edit your own entries', type: 'error');

            return;
        }

        $parsed = $this->parseLine($line);

        if ($parsed === null) {
            return;
        }

        $entry->update([
            'from_station' => $parsed['from'],
            'to_station' => $parsed['to'],
            // Keep the operator snapshot unless the station itself changed.
            'from_callsign' => $parsed['from'] === $entry->from_station ? $entry->from_callsign : $this->resolveCallsign($parsed['from']),
            'to_callsign' => $parsed['to'] === $entry->to_station ? $entry->to_callsign : $this->resolveCallsign($parsed['to']),
            'message' => $parsed['message'],
            // A recalled line carries its priority word, so none means routine.
            'priority' => $parsed['priority'] ?? NetPriority::Routine,
        ]);

        $this->broadcastChange($entry);
        $this->reset('exchangeInput', 'suggestions');
        unset($this->entries);

        $this->dispatch('toast', title: 'Entry updated', type: 'success');
    }

    public function deleteEntry(int $entryId): void
    {
        $entry = $this->findEventEntry($entryId);

        if (! auth()->user()?->can('delete', $entry)) {
            $this->dispatch('toast', title: 'You can only delete your own entries', type: 'error');

            return;
        }

        $entry->delete();
        $this->broadcastChange($entry);
        $this->reset('exchangeInput', 'suggestions');
        unset($this->entries);
    }

    public function restoreEntry(int $entryId): void
    {
        $entry = NetLogEntry::onlyTrashed()->forEvent($this->event->eventConfiguration->id)->findOrFail($entryId);

        if (! auth()->user()?->can('delete', $entry)) {
            return;
        }

        $entry->restore();
        $this->broadcastChange($entry);
        unset($this->entries);
    }

    /**
     * @return array{from: string, to: string, message: string, priority: NetPriority|null}|null
     */
    protected function parseLine(string $line): ?array
    {
        $this->parseError = null;

        try {
            $parsed = app(NetLogExchangeParser::class)->parse($line, $this->tacticalNames);
        } catch (InvalidArgumentException $exception) {
            $this->parseError = $exception->getMessage();

            return null;
        }

        $validator = validator(
            ['from' => $parsed['from'], 'to' => $parsed['to'], 'message' => $parsed['message']],
            ['from' => ['max:50'], 'to' => ['max:50'], 'message' => ['max:2000']],
        );

        if ($validator->fails()) {
            $this->parseError = $validator->errors()->first();

            return null;
        }

        return $parsed;
    }

    /**
     * Suggest tactical names for the station being typed: the called
     * station first, then the calling station.
     *
     * @return list<array{exchange: string, worked_on: string}>
     */
    protected function buildSuggestions(string $input): array
    {
        $input = ltrim((string) preg_replace('/\s+/', ' ', $input));

        if ($input === '' || $this->tacticalNames === []) {
            return [];
        }

        // Still typing the called station?
        $matches = $this->namesStartingWith($input);

        if ($matches === []) {
            // Then it's the calling station, after the called one.
            [$to, $afterTo] = app(NetLogExchangeParser::class)->takeStation($input, $this->tacticalNames);
            $matches = $afterTo === '' ? [] : $this->namesStartingWith($afterTo);
            $prefix = $to.' ';
        } else {
            $prefix = '';
        }

        return collect($matches)
            ->take(self::MAX_SUGGESTIONS)
            ->map(fn (string $name) => [
                'exchange' => $prefix.$name.' ',
                'worked_on' => $this->resolveCallsign($name) ?? 'Nobody on duty',
            ])
            ->values()
            ->all();
    }

    /**
     * Tactical names that start with, but don't already equal, what's typed.
     *
     * @return list<string>
     */
    protected function namesStartingWith(string $partial): array
    {
        $partial = mb_strtolower($partial);

        return array_values(array_filter(
            $this->tacticalNames,
            fn (string $name) => str_starts_with(mb_strtolower($name), $partial) && mb_strtolower($name) !== rtrim($partial),
        ));
    }

    /**
     * The net to show: the one asked for if it belongs to this event,
     * otherwise the net with the latest traffic, otherwise the newest net.
     */
    protected function resolveNetId(?int $netId): ?int
    {
        if ($netId !== null && $this->nets->contains('id', $netId)) {
            return $netId;
        }

        $latestTraffic = NetLogEntry::forEvent($this->event->eventConfiguration->id)
            ->whereNotNull('net_id')
            ->orderByDesc('logged_at')
            ->orderByDesc('id')
            ->value('net_id');

        return $latestTraffic ?? $this->nets->sortByDesc('id')->first()?->id;
    }

    /**
     * Find a net that belongs to this component's event, or 404.
     */
    protected function findEventNet(int $netId): Net
    {
        return Net::forEvent($this->event->eventConfiguration->id)->findOrFail($netId);
    }

    protected function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Find an entry that belongs to this component's event, or 404.
     */
    protected function findEventEntry(int $entryId): NetLogEntry
    {
        return NetLogEntry::forEvent($this->event->eventConfiguration->id)->findOrFail($entryId);
    }

    /**
     * Callsign of the operator on duty under a tactical name, snapshotted on
     * the entry so the log still shows who it was after the shift changes.
     */
    protected function resolveCallsign(?string $station): ?string
    {
        return app(TacticalCallsignResolver::class)->resolve($this->event->eventConfiguration, $station);
    }

    protected function broadcastChange(NetLogEntry $entry): void
    {
        NetLogChanged::dispatch($this->event->id, $entry->id);
    }

    public function render(): View
    {
        return view('livewire.net-log.net-log')->layout('layouts.app');
    }
}
