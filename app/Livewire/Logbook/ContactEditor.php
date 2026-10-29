<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Logbook;

use App\Events\ContactChanged;
use App\Models\AuditLog;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\User;
use App\Services\DuplicateCheckService;
use App\Services\ExchangeParserService;
use App\Support\BroadcastChannels;
use App\Support\EventSections;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class ContactEditor extends Component
{
    use AuthorizesRequests;

    /** Minute-precision QSO time, as the editor's datetime-local input uses it. */
    private const QSO_TIME_FORMAT = 'Y-m-d H:i';

    public bool $showModal = false;

    public ?int $contactId = null;

    public string $callsign = '';

    public string $exchangeClass = '';

    public ?int $sectionId = null;

    public ?int $bandId = null;

    public ?int $modeId = null;

    public string $submode = '';

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
        $this->submode = $contact->submode ?? '';
        $this->qsoTime = $contact->qso_time?->format(self::QSO_TIME_FORMAT) ?? '';
        $this->notes = $contact->notes ?? '';
        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $contact = Contact::findOrFail($this->contactId);
        $this->authorize('update', $contact);

        $this->callsign = mb_strtoupper(trim($this->callsign));

        $validated = $this->validate([
            'callsign' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9\/]+$/'],
            'exchangeClass' => ['required', 'string', 'max:5', 'regex:'.ExchangeParserService::EXCHANGE_CLASS_PATTERN],
            'sectionId' => 'required|exists:sections,id',
            'bandId' => 'required|exists:bands,id',
            'modeId' => 'required|exists:modes,id',
            'submode' => 'nullable|string|max:20',
            'qsoTime' => 'required|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $eventTypeId = $contact->eventConfiguration?->event?->event_type_id;
        $classCode = strtoupper(substr($this->exchangeClass, -1));

        $definedClasses = $eventTypeId === null ? [] : OperatingClass::query()
            ->where('event_type_id', $eventTypeId)
            ->pluck('code')
            ->map(fn (string $code): string => strtoupper($code))
            ->all();

        if ($definedClasses !== [] && ! in_array($classCode, $definedClasses, true)) {
            $this->addError('exchangeClass', "Class {$classCode} is not valid for this event.");

            return;
        }

        if (! $this->qsoTimeWithinEventPeriod($contact, $validated['qsoTime'])) {
            $this->addError('qsoTime', 'The QSO time must be within the event period.');

            return;
        }

        $oldValues = collect([
            'callsign' => $contact->callsign,
            'exchange_class' => $contact->exchange_class,
            'section_id' => $contact->section_id,
            'band_id' => $contact->band_id,
            'mode_id' => $contact->mode_id,
            'submode' => $contact->submode,
            'qso_time' => $contact->qso_time?->toDateTimeString(),
            'notes' => $contact->notes,
        ]);

        $dupes = app(DuplicateCheckService::class);
        $previousKey = $dupes->keyFor($contact);

        $contact->fill([
            'callsign' => $validated['callsign'],
            'exchange_class' => $validated['exchangeClass'],
            'band_id' => $validated['bandId'],
            'mode_id' => $validated['modeId'],
            'submode' => $validated['submode'] ?? null,
            // A newly picked YT or MX stores as TER or DX unless the event
            // recognises it; an unchanged section is left as logged.
            'section_id' => (int) $validated['sectionId'] === $contact->section_id
                ? $contact->section_id
                : EventSections::countedSectionId((int) $validated['sectionId'], $contact->eventConfiguration?->event),
            'notes' => $validated['notes'] ?: null,
        ]);

        // The form shows minutes only; keep the stored seconds unless the
        // time was actually changed, so a re-save cannot reorder a dupe group.
        if (! $this->qsoTimeUnchanged($contact, $validated['qsoTime'])) {
            $contact->qso_time = $validated['qsoTime'];
        }

        $dupes->save($contact, $previousKey);

        $newValues = collect([
            'callsign' => $contact->callsign,
            'exchange_class' => $contact->exchange_class,
            'section_id' => $contact->section_id,
            'band_id' => $contact->band_id,
            'mode_id' => $contact->mode_id,
            'submode' => $contact->submode,
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

        ContactChanged::notify(BroadcastChannels::CONTACT_UPDATED, [$contact]);

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

        $this->deleteAndReevaluate($contact);
        ContactChanged::notify(BroadcastChannels::CONTACT_DELETED, [$contact]);

        $this->dispatch('contact-deleted');
    }

    #[On('restore-contact')]
    public function restoreContact(int $contactId): void
    {
        $contact = Contact::onlyTrashed()->findOrFail($contactId);
        $this->authorize('restore', $contact);

        $dupes = app(DuplicateCheckService::class);
        $dupes->locked($contact->event_configuration_id, function () use ($dupes, $contact) {
            $contact->restore();
            $dupes->reevaluate($contact);
            $contact->operatingSession?->increment('qso_count');
        });

        AuditLog::log(
            'contact.restored',
            auditable: $contact,
            newValues: [
                'callsign' => $contact->callsign,
                'exchange_class' => $contact->exchange_class,
                'session_id' => $contact->operating_session_id,
            ],
        );

        ContactChanged::notify(BroadcastChannels::CONTACT_UPDATED, [$contact]);

        $this->dispatch('contact-restored');
    }

    #[On('bulk-delete-contacts')]
    public function bulkDeleteContacts(array $contactIds): void
    {
        if (empty($contactIds)) {
            return;
        }

        $contacts = Contact::whereIn('id', $contactIds)->get();
        $deleted = [];
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

            $this->deleteAndReevaluate($contact);
            $deleted[] = $contact;
            $count++;
        }

        if ($count > 0) {
            ContactChanged::notify(BroadcastChannels::CONTACT_DELETED, $deleted);
            $this->dispatch('contact-deleted');
            $this->dispatch('notify', title: 'Success', description: 'Deleted '.$count.' '.str('contact')->plural($count).'.', type: 'success');
        }
    }

    /**
     * Soft-delete a contact and settle its dupe group, promoting the next
     * dupe to original when the deleted contact held that place.
     */
    private function deleteAndReevaluate(Contact $contact): void
    {
        $dupes = app(DuplicateCheckService::class);
        $dupes->locked($contact->event_configuration_id, function () use ($dupes, $contact) {
            $contact->delete();
            $dupes->reevaluate($contact);
            $contact->operatingSession?->decrement('qso_count');
        });
    }

    /**
     * Whether a QSO time falls inside the contact's event period. An unchanged
     * time is always accepted so a contact entered within transcription's
     * clock-drift allowance can still have other fields corrected.
     */
    private function qsoTimeWithinEventPeriod(Contact $contact, string $qsoTime): bool
    {
        if ($this->qsoTimeUnchanged($contact, $qsoTime)) {
            return true;
        }

        $time = Carbon::parse($qsoTime);

        $event = $contact->eventConfiguration?->event;

        return $event?->isWithinOperatingPeriod($time) ?? true;
    }

    /**
     * Whether the edited QSO time matches the stored one to the minute.
     */
    private function qsoTimeUnchanged(Contact $contact, string $qsoTime): bool
    {
        return $contact->qso_time !== null
            && Carbon::parse($qsoTime)->format(self::QSO_TIME_FORMAT) === $contact->qso_time->format(self::QSO_TIME_FORMAT);
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
            $this->dispatch('notify', title: 'Success', description: 'Logger updated on '.$count.' '.str('contact')->plural($count).'.', type: 'success');
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
                $section->display_name = "{$section->code}: {$section->name}";

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

    /**
     * Get friendly field names for validation messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'sectionId' => 'section',
            'bandId' => 'band',
            'modeId' => 'mode',
            'qsoTime' => 'QSO time',
            'bulkLoggerUserId' => 'logger',
        ];
    }
}
