<?php

namespace App\Livewire\Messages;

use App\Models\BulletinScheduleEntry;
use App\Models\Event;
use App\Models\W1awBulletin;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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

    public ?int $reminderMinute = null;

    public function mount(Event $event): void
    {
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
            $bulletin->update($data);
        } else {
            $bulletin = W1awBulletin::create($data);
            $this->bulletinId = $bulletin->id;
        }

        $this->dispatch('toast', title: 'W1AW bulletin saved', type: 'success');
    }

    public function deleteBulletin(): void
    {
        if ($this->bulletinId) {
            $bulletin = W1awBulletin::findOrFail($this->bulletinId);
            $this->authorize('delete', $bulletin);
            $bulletin->delete();
            $this->bulletinId = null;
            $this->reset(['frequency', 'mode', 'receivedAt', 'bulletinText']);
            $this->dispatch('toast', title: 'Bulletin removed', type: 'success');
        }
    }

    public function getScheduleEntriesProperty(): \Illuminate\Database\Eloquent\Collection
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
        if (! auth()->user()->can('manage-event-config')) {
            abort(403);
        }

        $this->validate([
            'scheduleMode' => 'required|in:cw,digital,phone',
            'scheduleFrequencies' => 'required|string|max:255',
            'scheduleScheduledAt' => 'required|date',
            'scheduleSource' => 'required|in:W1AW,K6KPH',
        ]);

        BulletinScheduleEntry::create([
            'event_id' => $this->event->id,
            'scheduled_at' => $this->scheduleScheduledAt,
            'mode' => $this->scheduleMode,
            'frequencies' => $this->scheduleFrequencies,
            'source' => $this->scheduleSource,
            'created_by' => auth()->id(),
        ]);

        $this->reset(['scheduleMode', 'scheduleFrequencies', 'scheduleScheduledAt']);
        $this->scheduleSource = 'W1AW';
        $this->dispatch('toast', title: 'Transmission added', type: 'success');
    }

    public function editScheduleEntry(int $entryId): void
    {
        if (! auth()->user()->can('manage-event-config')) {
            abort(403);
        }

        $entry = BulletinScheduleEntry::findOrFail($entryId);
        $this->editingEntryId = $entry->id;
        $this->scheduleMode = $entry->mode;
        $this->scheduleFrequencies = $entry->frequencies;
        $this->scheduleSource = $entry->source;
        $this->scheduleScheduledAt = $entry->scheduled_at->format('Y-m-d\TH:i');
    }

    public function updateScheduleEntry(): void
    {
        if (! auth()->user()->can('manage-event-config')) {
            abort(403);
        }

        $this->validate([
            'scheduleMode' => 'required|in:cw,digital,phone',
            'scheduleFrequencies' => 'required|string|max:255',
            'scheduleScheduledAt' => 'required|date',
            'scheduleSource' => 'required|in:W1AW,K6KPH',
        ]);

        $entry = BulletinScheduleEntry::findOrFail($this->editingEntryId);
        $entry->update([
            'scheduled_at' => $this->scheduleScheduledAt,
            'mode' => $this->scheduleMode,
            'frequencies' => $this->scheduleFrequencies,
            'source' => $this->scheduleSource,
        ]);

        $this->editingEntryId = null;
        $this->reset(['scheduleMode', 'scheduleFrequencies', 'scheduleScheduledAt']);
        $this->scheduleSource = 'W1AW';
        $this->dispatch('toast', title: 'Transmission updated', type: 'success');
    }

    public function cancelEditScheduleEntry(): void
    {
        $this->editingEntryId = null;
        $this->reset(['scheduleMode', 'scheduleFrequencies', 'scheduleScheduledAt']);
        $this->scheduleSource = 'W1AW';
    }

    public function deleteScheduleEntry(int $entryId): void
    {
        if (! auth()->user()->can('manage-event-config')) {
            abort(403);
        }

        BulletinScheduleEntry::findOrFail($entryId)->delete();
        $this->dispatch('toast', title: 'Transmission removed', type: 'success');
    }

    public function getReminderMinutesProperty(): array
    {
        return auth()->user()->getBulletinReminderMinutes();
    }

    public function addReminderMinute(): void
    {
        $this->validate([
            'reminderMinute' => 'required|integer|min:1|max:60',
        ]);

        $current = auth()->user()->getBulletinReminderMinutes();

        if (count($current) >= 5) {
            $this->addError('reminderMinute', 'Maximum of 5 reminders allowed.');

            return;
        }

        if (in_array((int) $this->reminderMinute, $current, true)) {
            $this->addError('reminderMinute', 'This reminder time already exists.');

            return;
        }

        $current[] = (int) $this->reminderMinute;
        sort($current);
        auth()->user()->setBulletinReminderMinutes($current);

        $this->reminderMinute = null;
        $this->dispatch('toast', title: 'Reminder added', type: 'success');
    }

    public function removeReminderMinute(int $minutes): void
    {
        $current = auth()->user()->getBulletinReminderMinutes();
        $current = array_values(array_filter($current, fn ($m) => $m !== $minutes));
        auth()->user()->setBulletinReminderMinutes($current);
        $this->dispatch('toast', title: 'Reminder removed', type: 'success');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.messages.w1aw-bulletin-form')
            ->layout('components.layouts.app');
    }
}
