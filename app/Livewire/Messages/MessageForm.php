<?php

namespace App\Livewire\Messages;

use App\Models\Event;
use App\Models\Message;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class MessageForm extends Component
{
    use AuthorizesRequests;

    public Event $event;

    public ?Message $message = null;

    // Form fields
    public string $format = 'radiogram';

    public string $role = 'originated';

    public bool $isSmMessage = false;

    public ?int $messageNumber = null;

    public string $precedence = 'routine';

    public ?string $hxCode = null;

    public string $stationOfOrigin = '';

    public string $checkCount = '';

    public string $placeOfOrigin = '';

    public ?string $filedAt = null;

    public string $addresseeName = '';

    public ?string $addresseeAddress = null;

    public ?string $addresseeCity = null;

    public ?string $addresseeState = null;

    public ?string $addresseeZip = null;

    public ?string $addresseePhone = null;

    public string $messageText = '';

    public string $signature = '';

    public ?string $sentTo = null;

    public ?string $receivedFrom = null;

    public ?string $notes = null;

    public function mount(Event $event, ?string $template = null): void
    {
        $this->event = $event;

        if ($this->message && $this->message->exists) {
            $this->authorize('update', $this->message);
            $this->fillFromMessage($this->message);
        } else {
            $this->message = null;
            $this->authorize('create', Message::class);
            $this->stationOfOrigin = auth()->user()?->call_sign ?? '';
            $this->filedAt = now()->format('Y-m-d\TH:i');
        }

        if ($template === 'sm' && (! $this->message || ! $this->message->exists)) {
            $this->applySmTemplate();
        }
    }

    public function updatedMessageText(): void
    {
        $words = preg_split('/\s+/', trim($this->messageText), -1, PREG_SPLIT_NO_EMPTY);
        $this->checkCount = (string) count($words);
    }

    public function save(): void
    {
        // SM uniqueness check
        if ($this->isSmMessage) {
            $existingSmQuery = Message::where('event_configuration_id', $this->event->eventConfiguration->id)
                ->where('is_sm_message', true);

            if ($this->message) {
                $existingSmQuery->where('id', '!=', $this->message->id);
            }

            if ($existingSmQuery->exists()) {
                $this->addError('isSmMessage', 'An SM/SEC message already exists for this event.');

                return;
            }
        }

        $this->validate([
            'format' => 'required|in:radiogram,ics213',
            'role' => 'required|in:originated,relayed,received_delivered',
            'messageNumber' => 'required|integer|min:1',
            'stationOfOrigin' => 'required|string|max:20',
            'checkCount' => 'required|string|max:20',
            'placeOfOrigin' => 'required|string|max:255',
            'addresseeName' => 'required|string|max:255',
            'messageText' => 'required|string',
            'signature' => 'required|string|max:255',
            'precedence' => 'required|in:routine,welfare,priority,emergency',
            'hxCode' => 'nullable|in:hxa,hxb,hxc,hxd,hxe,hxf,hxg',
            'filedAt' => 'nullable|date',
            'addresseeAddress' => 'nullable|string|max:255',
            'addresseeCity' => 'nullable|string|max:255',
            'addresseeState' => 'nullable|string|max:10',
            'addresseeZip' => 'nullable|string|max:20',
            'addresseePhone' => 'nullable|string|max:30',
            'sentTo' => 'nullable|string|max:20',
            'receivedFrom' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
        ]);

        $data = [
            'event_configuration_id' => $this->event->eventConfiguration->id,
            'user_id' => auth()->id(),
            'format' => $this->format,
            'role' => $this->role,
            'is_sm_message' => $this->isSmMessage,
            'message_number' => $this->messageNumber,
            'precedence' => $this->precedence,
            'hx_code' => $this->hxCode,
            'station_of_origin' => strtoupper($this->stationOfOrigin),
            'check' => $this->checkCount,
            'place_of_origin' => $this->placeOfOrigin,
            'filed_at' => $this->filedAt,
            'addressee_name' => $this->addresseeName,
            'addressee_address' => $this->addresseeAddress,
            'addressee_city' => $this->addresseeCity,
            'addressee_state' => $this->addresseeState,
            'addressee_zip' => $this->addresseeZip,
            'addressee_phone' => $this->addresseePhone,
            'message_text' => strtoupper($this->messageText),
            'signature' => $this->signature,
            'sent_to' => $this->sentTo ? strtoupper($this->sentTo) : null,
            'received_from' => $this->receivedFrom ? strtoupper($this->receivedFrom) : null,
            'notes' => $this->notes,
        ];

        if ($this->message) {
            $this->message->update($data);
        } else {
            Message::create($data);
        }

        $this->dispatch('toast', title: 'Message saved', type: 'success');

        if (\Illuminate\Support\Facades\Route::has('events.messages.index')) {
            $this->redirect(route('events.messages.index', $this->event), navigate: true);
        }
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.messages.message-form', [
            'isEditing' => $this->message && $this->message->exists,
        ])->layout('layouts.app');
    }

    protected function fillFromMessage(Message $message): void
    {
        $this->format = $message->format->value;
        $this->role = $message->role->value;
        $this->isSmMessage = $message->is_sm_message;
        $this->messageNumber = $message->message_number;
        $this->precedence = $message->precedence->value;
        $this->hxCode = $message->hx_code?->value;
        $this->stationOfOrigin = $message->station_of_origin;
        $this->checkCount = $message->check;
        $this->placeOfOrigin = $message->place_of_origin;
        $this->filedAt = $message->filed_at?->format('Y-m-d\TH:i');
        $this->addresseeName = $message->addressee_name;
        $this->addresseeAddress = $message->addressee_address;
        $this->addresseeCity = $message->addressee_city;
        $this->addresseeState = $message->addressee_state;
        $this->addresseeZip = $message->addressee_zip;
        $this->addresseePhone = $message->addressee_phone;
        $this->messageText = $message->message_text;
        $this->signature = $message->signature;
        $this->sentTo = $message->sent_to;
        $this->receivedFrom = $message->received_from;
        $this->notes = $message->notes;
    }

    protected function applySmTemplate(): void
    {
        $this->isSmMessage = true;
        $this->role = 'originated';
        $this->precedence = 'routine';
        $config = $this->event->eventConfiguration;
        $clubName = $config->club_name ?? '[CLUB NAME]';
        $year = $this->event->start_time?->format('Y') ?? date('Y');
        $this->messageText = "{$clubName} FIELD DAY {$year} X\n[NUMBER] PARTICIPANTS X\nLOCATION [CITY STATE] X\n[NUMBER] ARES OPERATORS PARTICIPATING";
    }
}
