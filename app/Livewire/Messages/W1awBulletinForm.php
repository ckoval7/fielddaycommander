<?php

namespace App\Livewire\Messages;

use App\Models\AuditLog;
use App\Models\BulletinScheduleEntry;
use App\Models\Event;
use App\Models\W1awBulletin;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class W1awBulletinForm extends Component
{
    use AuthorizesRequests;

    public Event $event;

    public ?int $bulletinId = null;

    public string $frequency = '';

    public string $mode = '';

    public ?string $receivedAt = null;

    public string $bulletinText = '';

    // Schedule management properties
    public string $scheduleMode = '';

    public string $scheduleFrequencies = '';

    public string $scheduleSource = 'W1AW';

    public ?string $scheduleScheduledAt = null;

    public ?int $editingEntryId = null;

    public string $scheduleNotes = '';

    public function mount(): void
    {
        $event = app(EventContextService::class)->getContextEvent();

        if (! $event?->eventConfiguration) {
            abort(404);
        }

        $this->event = $event;
        $bulletin = W1awBulletin::where('event_configuration_id', $event->eventConfiguration->id)->first();

        if ($bulletin) {
            $this->bulletinId = $bulletin->id;
            $this->frequency = $bulletin->frequency;
            $this->mode = $bulletin->mode;
            $this->receivedAt = $bulletin->received_at->format('Y-m-d\TH:i');
            $this->bulletinText = $bulletin->bulletin_text;
        }
    }

    public function save(): void
    {
        $this->authorize('create', W1awBulletin::class);

        $this->validate([
            'frequency' => 'required|string|max:20',
            'mode' => 'required|in:cw,digital,phone',
            'receivedAt' => 'required|date',
            'bulletinText' => 'required|string',
        ]);

        $data = [
            'event_configuration_id' => $this->event->eventConfiguration->id,
            'user_id' => auth()->id(),
            'frequency' => $this->frequency,
            'mode' => $this->mode,
            'received_at' => $this->receivedAt,
            'bulletin_text' => $this->bulletinText,
        ];

        if ($this->bulletinId) {
            $bulletin = W1awBulletin::findOrFail($this->bulletinId);

            $oldValues = [
                'frequency' => $bulletin->frequency,
                'mode' => $bulletin->mode,
                'bulletin_text' => $bulletin->bulletin_text,
                'received_at' => $bulletin->received_at->toIso8601String(),
            ];

            $bulletin->update($data);

            $newValues = array_filter([
                'frequency' => $bulletin->frequency,
                'mode' => $bulletin->mode,
                'bulletin_text' => $bulletin->bulletin_text,
                'received_at' => $bulletin->received_at->toIso8601String(),
            ], fn ($value, $key) => $value !== $oldValues[$key], ARRAY_FILTER_USE_BOTH);

            $oldValues = array_intersect_key($oldValues, $newValues);

            if (! empty($newValues)) {
                AuditLog::log(
                    action: 'bulletin.updated',
                    auditable: $bulletin,
                    oldValues: $oldValues,
                    newValues: $newValues,
                );
            }
        } else {
            // Check for a soft-deleted bulletin for this event and restore it
            $trashed = W1awBulletin::onlyTrashed()
                ->where('event_configuration_id', $this->event->eventConfiguration->id)
                ->first();

            if ($trashed) {
                $trashed->restore();
                $trashed->update($data);
                $bulletin = $trashed;
            } else {
                $bulletin = W1awBulletin::create($data);
            }

            $this->bulletinId = $bulletin->id;

            AuditLog::log(
                action: 'bulletin.created',
                auditable: $bulletin,
                newValues: [
                    'frequency' => $bulletin->frequency,
                    'mode' => $bulletin->mode,
                    'bulletin_text' => $bulletin->bulletin_text,
                    'received_at' => $bulletin->received_at->toIso8601String(),
                ]
            );
        }

        $this->dispatch('toast', title: 'W1AW bulletin saved', type: 'success');
    }

    public function deleteBulletin(): void
    {
        if ($this->bulletinId) {
            $bulletin = W1awBulletin::findOrFail($this->bulletinId);
            $this->authorize('delete', $bulletin);

            AuditLog::log(
                action: 'bulletin.deleted',
                auditable: $bulletin,
                oldValues: [
                    'frequency' => $bulletin->frequency,
                    'mode' => $bulletin->mode,
                    'bulletin_text' => $bulletin->bulletin_text,
                ]
            );

            $bulletin->delete();
            $this->bulletinId = null;
            $this->reset(['frequency', 'mode', 'receivedAt', 'bulletinText']);
            $this->dispatch('toast', title: 'Bulletin removed', type: 'success');
        }
    }

    public function getScheduleEntriesProperty(): Collection
    {
        return BulletinScheduleEntry::forEvent($this->event->id)
            ->orderBy('scheduled_at')
            ->get();
    }

    public function getNextScheduleEntryProperty(): ?BulletinScheduleEntry
    {
        return $this->scheduleEntries->where('scheduled_at', '>', appNow())->first();
    }

    public function addScheduleEntry(): void
    {
        if (! auth()->user()->can('manage-bulletins')) {
            abort(403);
        }

        $this->validate([
            'scheduleMode' => 'required|in:cw,digital,phone',
            'scheduleFrequencies' => 'required|string|max:255',
            'scheduleScheduledAt' => 'required|date',
            'scheduleSource' => 'required|in:W1AW,K6KPH',
            'scheduleNotes' => 'nullable|string|max:500',
        ]);

        BulletinScheduleEntry::create([
            'event_id' => $this->event->id,
            'scheduled_at' => $this->scheduleScheduledAt,
            'mode' => $this->scheduleMode,
            'frequencies' => $this->scheduleFrequencies,
            'source' => $this->scheduleSource,
            'notes' => $this->scheduleNotes ?: null,
            'created_by' => auth()->id(),
        ]);

        $this->reset(['scheduleMode', 'scheduleFrequencies', 'scheduleScheduledAt', 'scheduleNotes']);
        $this->scheduleSource = 'W1AW';
        $this->dispatch('toast', title: 'Transmission added', type: 'success');
    }

    public function editScheduleEntry(int $entryId): void
    {
        if (! auth()->user()->can('manage-bulletins')) {
            abort(403);
        }

        $entry = BulletinScheduleEntry::findOrFail($entryId);
        $this->editingEntryId = $entry->id;
        $this->scheduleMode = $entry->mode;
        $this->scheduleFrequencies = $entry->frequencies;
        $this->scheduleSource = $entry->source;
        $this->scheduleScheduledAt = $entry->scheduled_at->format('Y-m-d\TH:i');
        $this->scheduleNotes = $entry->notes ?? '';
    }

    public function updateScheduleEntry(): void
    {
        if (! auth()->user()->can('manage-bulletins')) {
            abort(403);
        }

        $this->validate([
            'scheduleMode' => 'required|in:cw,digital,phone',
            'scheduleFrequencies' => 'required|string|max:255',
            'scheduleScheduledAt' => 'required|date',
            'scheduleSource' => 'required|in:W1AW,K6KPH',
            'scheduleNotes' => 'nullable|string|max:500',
        ]);

        $entry = BulletinScheduleEntry::findOrFail($this->editingEntryId);
        $entry->update([
            'scheduled_at' => $this->scheduleScheduledAt,
            'mode' => $this->scheduleMode,
            'frequencies' => $this->scheduleFrequencies,
            'source' => $this->scheduleSource,
            'notes' => $this->scheduleNotes ?: null,
        ]);

        $this->editingEntryId = null;
        $this->reset(['scheduleMode', 'scheduleFrequencies', 'scheduleScheduledAt', 'scheduleNotes']);
        $this->scheduleSource = 'W1AW';
        $this->dispatch('toast', title: 'Transmission updated', type: 'success');
    }

    public function cancelEditScheduleEntry(): void
    {
        $this->editingEntryId = null;
        $this->reset(['scheduleMode', 'scheduleFrequencies', 'scheduleScheduledAt', 'scheduleNotes']);
        $this->scheduleSource = 'W1AW';
    }

    public function deleteScheduleEntry(int $entryId): void
    {
        if (! auth()->user()->can('manage-bulletins')) {
            abort(403);
        }

        BulletinScheduleEntry::findOrFail($entryId)->delete();
        $this->dispatch('toast', title: 'Transmission removed', type: 'success');
    }

    /** @return Collection<int, AuditLog> */
    #[Computed]
    public function editHistory(): Collection
    {
        if (! $this->bulletinId) {
            return new Collection;
        }

        return AuditLog::query()
            ->where('auditable_type', W1awBulletin::class)
            ->where('auditable_id', $this->bulletinId)
            ->whereIn('action', ['bulletin.created', 'bulletin.updated'])
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Compute a line-by-line diff between two strings.
     *
     * @return list<array{type: string, text: string}>
     */
    protected function diffLines(string $old, string $new): array
    {
        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);
        $diff = [];

        $maxLen = max(count($oldLines), count($newLines));
        for ($i = 0; $i < $maxLen; $i++) {
            $oldLine = $oldLines[$i] ?? null;
            $newLine = $newLines[$i] ?? null;

            if ($oldLine === $newLine) {
                $diff[] = ['type' => 'unchanged', 'text' => $oldLine];
            } else {
                if ($oldLine !== null) {
                    $diff[] = ['type' => 'removed', 'text' => $oldLine];
                }
                if ($newLine !== null) {
                    $diff[] = ['type' => 'added', 'text' => $newLine];
                }
            }
        }

        return $diff;
    }

    public function render(): View
    {
        return view('livewire.messages.w1aw-bulletin-form')
            ->layout('components.layouts.app');
    }
}
