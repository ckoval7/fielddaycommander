<?php

namespace App\Livewire\Messages;

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

    public function mount(Event $event): void
    {
        $this->authorize('create', W1awBulletin::class);

        $this->event = $event;
        $bulletin = W1awBulletin::where('event_configuration_id', $event->eventConfiguration->id)->first();

        if ($bulletin) {
            $this->authorize('update', $bulletin);
            $this->bulletinId = $bulletin->id;
            $this->frequency = $bulletin->frequency;
            $this->mode = $bulletin->mode;
            $this->receivedAt = $bulletin->received_at->format('Y-m-d\TH:i');
            $this->bulletinText = $bulletin->bulletin_text;
        }
    }

    public function save(): void
    {
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

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.messages.w1aw-bulletin-form')
            ->layout('components.layouts.app');
    }
}
