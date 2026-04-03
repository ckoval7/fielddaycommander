<?php

namespace App\Livewire\Messages;

use App\Models\Event;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class MessageForm extends Component
{
    use AuthorizesRequests;

    private const DATETIME_LOCAL_FORMAT = 'Y-m-d\TH:i';

    public Event $event;

    public ?Message $message = null;

    // Form fields
    public string $format = 'radiogram';

    public string $role = 'originated';

    public bool $isSmMessage = false;

    public ?int $messageNumber = null;

    public string $precedence = 'routine';

    public ?string $hxCode = null;

    public ?string $hxValue = null;

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

    public ?string $frequency = null;

    public ?string $modeCategory = null;

    public ?string $notes = null;

    // ICS-213 fields
    public ?string $icsToPosition = null;

    public ?string $icsFromPosition = null;

    public ?string $icsSubject = null;

    public ?string $icsReplyText = null;

    public ?string $icsReplyDate = null;

    public ?string $icsReplyName = null;

    public ?string $icsReplyPosition = null;

    public function mount(Event $event, ?string $template = null): void
    {
        $this->event = $event;

        if ($this->message && $this->message->exists) {
            $this->authorize('update', $this->message);
            $this->fillFromMessage($this->message);
        } else {
            $this->message = null;
            $this->authorize('create', Message::class);
            $this->stationOfOrigin = $this->event->eventConfiguration->callsign ?? '';
            $this->filedAt = now()->format(self::DATETIME_LOCAL_FORMAT);
        }

        if ($template === 'sm' && (! $this->message || ! $this->message->exists)) {
            $this->applySmTemplate();
        }
    }

    public function updatedMessageText(): void
    {
        if ($this->format === 'radiogram') {
            $words = preg_split('/\s+/', trim($this->messageText), -1, PREG_SPLIT_NO_EMPTY);
            $this->checkCount = (string) count($words);
        }
    }

    public function updatedHxCode(): void
    {
        if (! in_array($this->hxCode, ['hxb', 'hxc', 'hxd', 'hxe', 'hxf'])) {
            $this->hxValue = null;
        }
    }

    public function updatedFormat(): void
    {
        if ($this->format === 'ics213') {
            $this->precedence = 'routine';
            $this->hxCode = null;
            $this->hxValue = null;
            $this->checkCount = '';
            $this->placeOfOrigin = '';
            $this->addresseeAddress = null;
            $this->addresseeCity = null;
            $this->addresseeState = null;
            $this->addresseeZip = null;
            $this->addresseePhone = null;
            $this->sentTo = null;
            $this->receivedFrom = null;
        } elseif ($this->format === 'radiogram') {
            $this->icsToPosition = null;
            $this->icsFromPosition = null;
            $this->icsSubject = null;
            $this->icsReplyText = null;
            $this->icsReplyDate = null;
            $this->icsReplyName = null;
            $this->icsReplyPosition = null;
            $this->stationOfOrigin = $this->event->eventConfiguration->callsign ?? '';
        }
    }

    public function save(): void
    {
        if (! $this->validateSmUniqueness()) {
            return;
        }

        $this->validate($this->validationRules());

        $data = $this->buildMessageData();

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

    private function validateSmUniqueness(): bool
    {
        if (! $this->isSmMessage) {
            return true;
        }

        $existingSmQuery = Message::where('event_configuration_id', $this->event->eventConfiguration->id)
            ->where('is_sm_message', true);

        if ($this->message) {
            $existingSmQuery->where('id', '!=', $this->message->id);
        }

        if ($existingSmQuery->exists()) {
            $this->addError('isSmMessage', 'An SM/SEC message already exists for this event.');

            return false;
        }

        return true;
    }

    private function validationRules(): array
    {
        $sharedRules = [
            'format' => 'required|in:radiogram,ics213',
            'role' => 'required|in:originated,relayed,received_delivered',
            'messageNumber' => 'required|integer|min:1',
            'addresseeName' => 'required|string|max:255',
            'messageText' => 'required|string',
            'signature' => 'required|string|max:255',
            'filedAt' => 'nullable|date',
            'notes' => 'nullable|string',
            'frequency' => 'nullable|string|max:15',
            'modeCategory' => 'nullable|in:CW,Phone,Digital',
        ];

        $formatRules = $this->format === 'radiogram'
            ? [
                'precedence' => 'required|in:routine,welfare,priority,emergency',
                'hxCode' => 'nullable|in:hxa,hxb,hxc,hxd,hxe,hxf,hxg',
                'hxValue' => 'nullable|string|max:20',
                'stationOfOrigin' => 'required|string|max:20',
                'checkCount' => 'required|string|max:20',
                'placeOfOrigin' => 'required|string|max:255',
                'addresseeAddress' => 'nullable|string|max:255',
                'addresseeCity' => 'nullable|string|max:255',
                'addresseeState' => 'nullable|string|max:10',
                'addresseeZip' => 'nullable|string|max:20',
                'addresseePhone' => 'nullable|string|max:30',
                'sentTo' => 'nullable|string|max:20',
                'receivedFrom' => 'nullable|string|max:20',
            ]
            : [
                'icsSubject' => 'required|string|max:255',
                'icsToPosition' => 'nullable|string|max:255',
                'icsFromPosition' => 'nullable|string|max:255',
                'icsReplyText' => 'nullable|string',
                'icsReplyDate' => 'nullable|date',
                'icsReplyName' => 'nullable|string|max:255',
                'icsReplyPosition' => 'nullable|string|max:255',
            ];

        return array_merge($sharedRules, $formatRules);
    }

    private function buildMessageData(): array
    {
        $isReceivedDelivered = $this->role === 'received_delivered';

        $sharedData = [
            'event_configuration_id' => $this->event->eventConfiguration->id,
            'user_id' => auth()->id(),
            'format' => $this->format,
            'role' => $this->role,
            'is_sm_message' => $this->isSmMessage,
            'message_number' => $this->messageNumber,
            'filed_at' => $this->filedAt,
            'addressee_name' => $this->addresseeName,
            'signature' => $this->signature,
            'notes' => $this->notes,
            'frequency' => $isReceivedDelivered && $this->frequency ? $this->frequency : null,
            'mode_category' => $isReceivedDelivered && $this->modeCategory ? $this->modeCategory : null,
        ];

        $formatData = $this->format === 'radiogram'
            ? $this->buildRadiogramData()
            : $this->buildIcsData();

        return array_merge($sharedData, $formatData);
    }

    private function buildRadiogramData(): array
    {
        return [
            'precedence' => $this->precedence,
            'hx_code' => $this->hxCode,
            'hx_value' => in_array($this->hxCode, ['hxb', 'hxc', 'hxd', 'hxe', 'hxf']) ? $this->hxValue : null,
            'station_of_origin' => strtoupper($this->stationOfOrigin),
            'check' => $this->checkCount,
            'place_of_origin' => $this->placeOfOrigin,
            'addressee_address' => $this->addresseeAddress,
            'addressee_city' => $this->addresseeCity,
            'addressee_state' => $this->addresseeState,
            'addressee_zip' => $this->addresseeZip,
            'addressee_phone' => $this->addresseePhone,
            'message_text' => strtoupper($this->messageText),
            'sent_to' => $this->sentTo ? strtoupper($this->sentTo) : null,
            'received_from' => $this->receivedFrom ? strtoupper($this->receivedFrom) : null,
            // Null out ICS-213 fields
            'ics_to_position' => null,
            'ics_from_position' => null,
            'ics_subject' => null,
            'ics_reply_text' => null,
            'ics_reply_date' => null,
            'ics_reply_name' => null,
            'ics_reply_position' => null,
        ];
    }

    private function buildIcsData(): array
    {
        return [
            'message_text' => $this->messageText,
            'ics_to_position' => $this->icsToPosition,
            'ics_from_position' => $this->icsFromPosition,
            'ics_subject' => $this->icsSubject,
            'ics_reply_text' => $this->icsReplyText,
            'ics_reply_date' => $this->icsReplyDate,
            'ics_reply_name' => $this->icsReplyName,
            'ics_reply_position' => $this->icsReplyPosition,
            // Null out radiogram fields
            'precedence' => null,
            'hx_code' => null,
            'hx_value' => null,
            'station_of_origin' => null,
            'check' => null,
            'place_of_origin' => null,
            'addressee_address' => null,
            'addressee_city' => null,
            'addressee_state' => null,
            'addressee_zip' => null,
            'addressee_phone' => null,
            'sent_to' => null,
            'received_from' => null,
        ];
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.messages.message-form', [
            'isEditing' => $this->message && $this->message->exists,
            'ics213Enabled' => Setting::getBoolean('enable_ics213', false),
        ])->layout('layouts.app');
    }

    protected function fillFromMessage(Message $message): void
    {
        $this->format = $message->format->value;
        $this->role = $message->role->value;
        $this->isSmMessage = $message->is_sm_message;
        $this->messageNumber = $message->message_number;
        $this->filedAt = $message->filed_at?->format(self::DATETIME_LOCAL_FORMAT);
        $this->addresseeName = $message->addressee_name;
        $this->messageText = $message->message_text;
        $this->signature = $message->signature;
        $this->notes = $message->notes;

        // Radiogram fields
        $this->precedence = $message->precedence?->value ?? 'routine';
        $this->hxCode = $message->hx_code?->value;
        $this->hxValue = $message->hx_value;
        $this->stationOfOrigin = $message->station_of_origin ?? '';
        $this->checkCount = $message->check ?? '';
        $this->placeOfOrigin = $message->place_of_origin ?? '';
        $this->addresseeAddress = $message->addressee_address;
        $this->addresseeCity = $message->addressee_city;
        $this->addresseeState = $message->addressee_state;
        $this->addresseeZip = $message->addressee_zip;
        $this->addresseePhone = $message->addressee_phone;
        $this->sentTo = $message->sent_to;
        $this->receivedFrom = $message->received_from;

        // Frequency & mode
        $this->frequency = $message->frequency;
        $this->modeCategory = $message->mode_category;

        // ICS-213 fields
        $this->icsToPosition = $message->ics_to_position;
        $this->icsFromPosition = $message->ics_from_position;
        $this->icsSubject = $message->ics_subject;
        $this->icsReplyText = $message->ics_reply_text;
        $this->icsReplyDate = $message->ics_reply_date?->format(self::DATETIME_LOCAL_FORMAT);
        $this->icsReplyName = $message->ics_reply_name;
        $this->icsReplyPosition = $message->ics_reply_position;
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
