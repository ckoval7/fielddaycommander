<?php

namespace App\Livewire\Messages;

use App\Models\Event;
use App\Models\Message;
use App\Services\MessageBonusSyncService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class MessageTrafficIndex extends Component
{
    use AuthorizesRequests;

    public Event $event;

    public ?string $roleFilter = null;

    public function mount(Event $event): void
    {
        $this->event = $event;
    }

    #[Computed]
    public function messages(): Collection
    {
        $query = Message::where('event_configuration_id', $this->event->eventConfiguration->id)
            ->with('user')
            ->orderBy('message_number');

        if ($this->roleFilter) {
            $query->where('role', $this->roleFilter);
        }

        return $query->get();
    }

    #[Computed]
    public function bonusSummary(): array
    {
        return app(MessageBonusSyncService::class)->bonusSummary($this->event->eventConfiguration);
    }

    public function deleteMessage(int $messageId): void
    {
        $message = Message::findOrFail($messageId);
        $this->authorize('delete', $message);
        $message->delete();

        unset($this->messages);
        unset($this->bonusSummary);

        $this->dispatch('toast', title: 'Message deleted', type: 'success');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.messages.message-traffic-index')
            ->layout('components.layouts.app');
    }
}
