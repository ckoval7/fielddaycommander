<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\NetLog;

use App\Enums\NetPriority;
use App\Events\NetLogChanged;
use App\Livewire\NetLog\Concerns\EditsNetLogEntries;
use App\Livewire\NetLog\Concerns\HasNetSuggestions;
use App\Livewire\NetLog\Concerns\RunsRollCalls;
use App\Models\Event;
use App\Models\Net;
use App\Models\NetLogEntry;
use App\Services\NetLogExchangeParser;
use App\Services\StationLastHeardService;
use App\Services\TacticalCallsignResolver;
use App\Support\BroadcastChannels;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
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
    use EditsNetLogEntries, HasNetSuggestions, RunsRollCalls;

    /**
     * Entries shown in the log window. The full log is on the ICS-309.
     */
    private const VISIBLE_ENTRIES = 100;

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
     * Each net position with its location, operator on duty and when it was
     * last heard, flagged overdue past this net's threshold.
     *
     * @return SupportCollection<int, array<string, mixed>>
     */
    #[Computed]
    public function stationStatus(): SupportCollection
    {
        return app(StationLastHeardService::class)->forEvent($this->event->eventConfiguration, $this->currentNet);
    }

    public function refreshEntries(): void
    {
        $this->reloadNets($this->netId);
    }

    /**
     * The net form started, edited or deleted a net: show it, or after a
     * delete, the next net.
     */
    #[On('net-changed')]
    public function netChanged(?int $netId = null): void
    {
        $this->reloadNets($netId);
    }

    /**
     * Switch this screen to another net.
     */
    public function updatedNetId(): void
    {
        unset($this->currentNet, $this->entries, $this->tacticalNames, $this->stationStatus, $this->activeRollCall);
        $this->netId = $this->resolveNetId($this->netId);
        $this->reset('exchangeInput', 'suggestions', 'parseError', 'priority');
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

        $this->recordRollCallAnswer($entry);
        NetLogChanged::dispatch($this->event->id, $entry->id);
        $this->reset('exchangeInput', 'suggestions', 'priority');
        unset($this->entries, $this->stationStatus);

        $this->dispatch('contact-logged');
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
     * Re-read the event's nets and show the given net, or the best one left.
     */
    private function reloadNets(?int $netId): void
    {
        unset($this->nets, $this->currentNet, $this->entries, $this->tacticalNames, $this->stationStatus, $this->activeRollCall);
        $this->netId = $this->resolveNetId($netId);
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

        return Net::busiestForEvent($this->event->eventConfiguration->id)?->id;
    }

    /**
     * Callsign of the operator on duty under a tactical name, snapshotted on
     * the entry so the log still shows who it was after the shift changes.
     */
    protected function resolveCallsign(?string $station): ?string
    {
        return app(TacticalCallsignResolver::class)->resolve($this->event->eventConfiguration, $station);
    }

    public function render(): View
    {
        return view('livewire.net-log.net-log')->layout('layouts.app');
    }
}
