<?php

namespace App\Livewire\Messages;

use App\Models\Event;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
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

    public bool $showSentByModal = false;

    public ?int $editingSentMessageId = null;

    public ?int $selectedSentByUserId = null;

    public bool $isDeliveryModal = false;

    public ?string $sentFrequency = null;

    public ?string $sentModeCategory = null;

    public function mount(Event $event): void
    {
        $this->event = $event;
    }

    #[Computed]
    public function messages(): Collection
    {
        $query = Message::where('event_configuration_id', $this->event->eventConfiguration->id)
            ->with(['user', 'sentByUser'])
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

    #[Computed]
    public function operators(): \Illuminate\Support\Collection
    {
        return User::permission('log-contacts')
            ->orderBy('call_sign')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->call_sign
                    ? "{$user->call_sign} — {$user->name}"
                    : $user->name,
            ]);
    }

    public function openSentByModal(int $messageId): void
    {
        $message = Message::findOrFail($messageId);
        $this->editingSentMessageId = $messageId;
        $this->selectedSentByUserId = auth()->id();
        $this->isDeliveryModal = $message->role->value === 'received_delivered';
        $this->sentFrequency = $message->frequency;
        $this->sentModeCategory = $message->mode_category;
        $this->showSentByModal = true;
    }

    public function editSentBy(int $messageId): void
    {
        $message = Message::findOrFail($messageId);
        $this->editingSentMessageId = $messageId;
        $this->selectedSentByUserId = $message->sent_by_user_id;
        $this->isDeliveryModal = $message->role->value === 'received_delivered';
        $this->sentFrequency = $message->frequency;
        $this->sentModeCategory = $message->mode_category;
        $this->showSentByModal = true;
    }

    public function saveSentBy(): void
    {
        $message = Message::findOrFail($this->editingSentMessageId);

        $message->update([
            'sent_at' => $message->sent_at ?? now(),
            'sent_by_user_id' => $this->selectedSentByUserId,
            'frequency' => $this->sentFrequency ?: null,
            'mode_category' => $this->sentModeCategory ?: null,
        ]);

        $label = $this->isDeliveryModal ? 'delivered' : 'sent';

        $this->showSentByModal = false;
        $this->editingSentMessageId = null;
        $this->selectedSentByUserId = null;
        $this->sentFrequency = null;
        $this->sentModeCategory = null;
        $this->isDeliveryModal = false;

        unset($this->messages);

        $this->dispatch('toast', title: "Message marked as {$label}", type: 'success');
    }

    public function markAsSent(int $messageId): void
    {
        $message = Message::findOrFail($messageId);
        $label = $message->role->value === 'received_delivered' ? 'delivered' : 'sent';

        $message->update([
            'sent_at' => now(),
            'sent_by_user_id' => auth()->id(),
        ]);

        unset($this->messages);

        $this->dispatch('toast', title: "Message marked as {$label}", type: 'success');
    }

    public function unmarkAsSent(int $messageId): void
    {
        $message = Message::findOrFail($messageId);
        $label = $message->role->value === 'received_delivered' ? 'Delivered' : 'Sent';

        $message->update([
            'sent_at' => null,
            'sent_by_user_id' => null,
            'frequency' => null,
            'mode_category' => null,
        ]);

        unset($this->messages);

        $this->dispatch('toast', title: "{$label} status cleared", type: 'success');
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
        return view('livewire.messages.message-traffic-index', [
            'ics213Enabled' => Setting::getBoolean('enable_ics213', false),
        ])->layout('components.layouts.app');
    }
}
