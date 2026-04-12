<?php

namespace App\Livewire\Logbook;

use App\Models\AuditLog;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Mode;
use App\Services\ExchangeParserService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class ContactEditor extends Component
{
    use AuthorizesRequests;

    public bool $showModal = false;

    public ?int $contactId = null;

    public string $callsign = '';

    public string $received_exchange = '';

    public ?int $band_id = null;

    public ?int $mode_id = null;

    public string $qso_time = '';

    public string $notes = '';

    #[On('open-edit-contact')]
    public function openEdit(int $contactId): void
    {
        $contact = Contact::findOrFail($contactId);
        $this->authorize('update', $contact);

        $this->contactId = $contact->id;
        $this->callsign = $contact->callsign ?? '';
        $this->received_exchange = $contact->received_exchange ?? '';
        $this->band_id = $contact->band_id;
        $this->mode_id = $contact->mode_id;
        $this->qso_time = $contact->qso_time?->format('Y-m-d H:i') ?? '';
        $this->notes = $contact->notes ?? '';
        $this->showModal = true;
    }

    public function save(): void
    {
        $contact = Contact::findOrFail($this->contactId);
        $this->authorize('update', $contact);

        $validated = $this->validate([
            'callsign' => 'required|string|max:20',
            'received_exchange' => 'required|string|max:50',
            'band_id' => 'required|exists:bands,id',
            'mode_id' => 'required|exists:modes,id',
            'qso_time' => 'required|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $oldValues = collect([
            'callsign' => $contact->callsign,
            'received_exchange' => $contact->received_exchange,
            'section_id' => $contact->section_id,
            'band_id' => $contact->band_id,
            'mode_id' => $contact->mode_id,
            'qso_time' => $contact->qso_time?->toDateTimeString(),
            'notes' => $contact->notes,
        ]);

        // Resolve section from received exchange
        $parsed = app(ExchangeParserService::class)->parse($validated['received_exchange']);
        $sectionId = $parsed['success'] ? $parsed['section_id'] : $contact->section_id;

        // Re-run duplicate detection against the new values
        $isDuplicate = Contact::query()
            ->where('event_configuration_id', $contact->event_configuration_id)
            ->where('band_id', $validated['band_id'])
            ->where('mode_id', $validated['mode_id'])
            ->where('callsign', mb_strtoupper($validated['callsign']))
            ->where('is_gota_contact', $contact->is_gota_contact)
            ->where('id', '!=', $contact->id)
            ->where('is_duplicate', false)
            ->exists();

        $mode = Mode::find($validated['mode_id']);

        $contact->update([
            'callsign' => $validated['callsign'],
            'received_exchange' => $validated['received_exchange'],
            'band_id' => $validated['band_id'],
            'mode_id' => $validated['mode_id'],
            'qso_time' => $validated['qso_time'],
            'section_id' => $sectionId,
            'notes' => $validated['notes'] ?: null,
            'is_duplicate' => $isDuplicate,
            'points' => $isDuplicate ? 0 : ($mode->points_fd ?? 1),
        ]);

        $newValues = collect([
            'callsign' => $contact->callsign,
            'received_exchange' => $contact->received_exchange,
            'section_id' => $contact->section_id,
            'band_id' => $contact->band_id,
            'mode_id' => $contact->mode_id,
            'qso_time' => $contact->qso_time?->toDateTimeString(),
            'notes' => $contact->notes,
        ]);

        // Only log changed fields
        $changed = $newValues->filter(fn ($value, $key) => $value !== $oldValues[$key]);
        if ($changed->isNotEmpty()) {
            AuditLog::log(
                'contact.updated',
                auditable: $contact,
                oldValues: $oldValues->only($changed->keys())->toArray(),
                newValues: $changed->toArray(),
            );
        }

        $this->showModal = false;
        $this->dispatch('contact-updated');
    }

    #[On('delete-contact')]
    public function deleteContact(int $contactId): void
    {
        $contact = Contact::findOrFail($contactId);
        $this->authorize('delete', $contact);

        AuditLog::log(
            'contact.deleted',
            auditable: $contact,
            oldValues: [
                'callsign' => $contact->callsign,
                'received_exchange' => $contact->received_exchange,
                'session_id' => $contact->operating_session_id,
            ],
        );

        $contact->delete();
        $contact->operatingSession->decrement('qso_count');

        $this->dispatch('contact-deleted');
    }

    #[On('restore-contact')]
    public function restoreContact(int $contactId): void
    {
        $contact = Contact::onlyTrashed()->findOrFail($contactId);
        $this->authorize('restore', $contact);

        $contact->restore();
        $contact->operatingSession->increment('qso_count');

        AuditLog::log(
            'contact.restored',
            auditable: $contact,
            newValues: [
                'callsign' => $contact->callsign,
                'received_exchange' => $contact->received_exchange,
                'session_id' => $contact->operating_session_id,
            ],
        );

        $this->dispatch('contact-restored');
    }

    #[Computed]
    public function bands()
    {
        return Band::orderBy('name')->get();
    }

    #[Computed]
    public function modes()
    {
        return Mode::orderBy('name')->get();
    }

    public function render(): View
    {
        return view('livewire.logbook.contact-editor');
    }
}
