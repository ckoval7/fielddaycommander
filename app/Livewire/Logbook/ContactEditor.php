<?php

namespace App\Livewire\Logbook;

use App\Models\AuditLog;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Mode;
use App\Models\Section;
use App\Models\User;
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

    public string $exchangeClass = '';

    public ?int $sectionId = null;

    public ?int $bandId = null;

    public ?int $modeId = null;

    public string $qsoTime = '';

    public string $notes = '';

    public bool $showBulkLoggerModal = false;

    public array $bulkLoggerContactIds = [];

    public ?int $bulkLoggerUserId = null;

    #[On('open-edit-contact')]
    public function openEdit(int $contactId): void
    {
        $contact = Contact::findOrFail($contactId);
        $this->authorize('update', $contact);

        $this->contactId = $contact->id;
        $this->callsign = $contact->callsign ?? '';
        $this->exchangeClass = $contact->exchange_class ?? '';
        $this->sectionId = $contact->section_id;
        $this->bandId = $contact->band_id;
        $this->modeId = $contact->mode_id;
        $this->qsoTime = $contact->qso_time?->format('Y-m-d H:i') ?? '';
        $this->notes = $contact->notes ?? '';
        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $contact = Contact::findOrFail($this->contactId);
        $this->authorize('update', $contact);

        $validated = $this->validate([
            'callsign' => 'required|string|max:20',
            'exchangeClass' => ['required', 'string', 'max:5', 'regex:/^\d{1,2}[A-F]$/i'],
            'sectionId' => 'required|exists:sections,id',
            'bandId' => 'required|exists:bands,id',
            'modeId' => 'required|exists:modes,id',
            'qsoTime' => 'required|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $oldValues = collect([
            'callsign' => $contact->callsign,
            'exchange_class' => $contact->exchange_class,
            'section_id' => $contact->section_id,
            'band_id' => $contact->band_id,
            'mode_id' => $contact->mode_id,
            'qso_time' => $contact->qso_time?->toDateTimeString(),
            'notes' => $contact->notes,
        ]);

        // Re-run duplicate detection against the new values
        $isDuplicate = Contact::query()
            ->where('event_configuration_id', $contact->event_configuration_id)
            ->where('band_id', $validated['bandId'])
            ->where('mode_id', $validated['modeId'])
            ->where('callsign', mb_strtoupper($validated['callsign']))
            ->where('is_gota_contact', $contact->is_gota_contact)
            ->where('id', '!=', $contact->id)
            ->where('is_duplicate', false)
            ->exists();

        $mode = Mode::find($validated['modeId']);

        $contact->update([
            'callsign' => $validated['callsign'],
            'exchange_class' => $validated['exchangeClass'],
            'band_id' => $validated['bandId'],
            'mode_id' => $validated['modeId'],
            'qso_time' => $validated['qsoTime'],
            'section_id' => $validated['sectionId'],
            'notes' => $validated['notes'] ?: null,
            'is_duplicate' => $isDuplicate,
            'points' => $isDuplicate ? 0 : ($mode->points_fd ?? 1),
        ]);

        $newValues = collect([
            'callsign' => $contact->callsign,
            'exchange_class' => $contact->exchange_class,
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
                'exchange_class' => $contact->exchange_class,
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
                'exchange_class' => $contact->exchange_class,
                'session_id' => $contact->operating_session_id,
            ],
        );

        $this->dispatch('contact-restored');
    }

    #[On('bulk-delete-contacts')]
    public function bulkDeleteContacts(array $contactIds): void
    {
        if (empty($contactIds)) {
            return;
        }

        $contacts = Contact::whereIn('id', $contactIds)->get();
        $count = 0;

        foreach ($contacts as $contact) {
            if (auth()->user()->cannot('delete', $contact)) {
                continue;
            }

            AuditLog::log(
                'contact.deleted',
                auditable: $contact,
                oldValues: [
                    'callsign' => $contact->callsign,
                    'exchange_class' => $contact->exchange_class,
                    'session_id' => $contact->operating_session_id,
                ],
            );

            $contact->delete();
            $contact->operatingSession->decrement('qso_count');
            $count++;
        }

        if ($count > 0) {
            $this->dispatch('contact-deleted');
            $this->dispatch('notify', title: 'Success', description: "{$count} contact(s) deleted.", type: 'success');
        }
    }

    #[On('open-bulk-change-logger')]
    public function openBulkChangeLogger(array $contactIds): void
    {
        $this->bulkLoggerContactIds = $contactIds;
        $this->bulkLoggerUserId = null;
        $this->resetValidation();
        $this->showBulkLoggerModal = true;
    }

    public function bulkChangeLogger(): void
    {
        $this->validate([
            'bulkLoggerUserId' => 'required|exists:users,id',
        ]);

        $contacts = Contact::whereIn('id', $this->bulkLoggerContactIds)->get();
        $count = 0;

        foreach ($contacts as $contact) {
            if (auth()->user()->cannot('update', $contact)) {
                continue;
            }

            $oldLoggerId = $contact->logger_user_id;
            $contact->update(['logger_user_id' => $this->bulkLoggerUserId]);

            AuditLog::log(
                'contact.updated',
                auditable: $contact,
                oldValues: ['logger_user_id' => $oldLoggerId],
                newValues: ['logger_user_id' => $this->bulkLoggerUserId],
            );

            $count++;
        }

        $this->showBulkLoggerModal = false;

        if ($count > 0) {
            $this->dispatch('contact-updated');
            $this->dispatch('notify', title: 'Success', description: "Logger updated for {$count} contact(s).", type: 'success');
        }
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

    #[Computed]
    public function sections()
    {
        return Section::where('is_active', true)->orderBy('code')->get()
            ->map(function (Section $section) {
                $section->display_name = "{$section->code} – {$section->name}";

                return $section;
            });
    }

    #[Computed]
    public function operators()
    {
        return User::orderBy('call_sign')
            ->get()
            ->map(function (User $user) {
                $user->display_name = $user->first_name
                    ? "{$user->first_name}, {$user->call_sign}"
                    : $user->call_sign;

                return $user;
            });
    }

    public function render(): View
    {
        return view('livewire.logbook.contact-editor');
    }
}
