<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Requests;

use App\Models\Band;
use App\Models\OperatingClass;
use App\Models\OperatingSession;
use App\Services\BandResolverService;
use App\Services\ExchangeParserService;
use App\Support\AdvancedContactFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreContactSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'callsign' => strtoupper(trim($this->callsign ?? '')),
            ...AdvancedContactFields::normalize($this->all()),
        ]);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'operating_session_id' => ['required', 'integer', 'exists:operating_sessions,id'],
            'band_id' => ['required', 'integer', 'exists:bands,id'],
            'mode_id' => ['required', 'integer', 'exists:modes,id'],
            'submode' => ['nullable', 'string', 'max:20'],
            'callsign' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9\/]+$/'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'exchange_class' => ['required', 'string', 'max:5', 'regex:'.ExchangeParserService::EXCHANGE_CLASS_PATTERN],
            'power_watts' => ['required', 'integer', 'min:1', 'max:1500'],
            'qso_time' => ['required', 'date'],
            'is_gota_contact' => ['sometimes', 'boolean'],
            'gota_operator_first_name' => ['nullable', 'string', 'max:50'],
            'gota_operator_last_name' => ['nullable', 'string', 'max:50'],
            'gota_operator_callsign' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9\/]*$/'],
            'gota_operator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            ...AdvancedContactFields::rules(),
        ];
    }

    /**
     * Reject class letters the event's own rulebook does not define, so a
     * Field Day log cannot accept Winter Field Day classes or vice versa.
     *
     * Runs after the base rules so it can rely on a well-formed class token
     * and an existing operating session.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $session = OperatingSession::find($this->operating_session_id);
                $sessionError = $this->sessionMismatch($session);

                if ($sessionError !== null) {
                    $validator->errors()->add(...$sessionError);

                    return;
                }

                $this->validateClassForEvent($validator, $session);
            },
        ];
    }

    /**
     * The first way this contact conflicts with its operating session, as a
     * field and message, or null when it fits.
     *
     * GOTA contacts are credited to the GOTA operator, so one must be named,
     * and a session operates on one band and mode, so contacts (and any dial
     * frequency) must match it. The free VHF station can't log below 50 MHz.
     *
     * @return array{0: string, 1: string}|null
     */
    private function sessionMismatch(?OperatingSession $session): ?array
    {
        return match (true) {
            $session?->station->is_gota && ! $this->hasGotaOperator() => [
                'gota_operator_first_name',
                'Enter the GOTA operator in the GOTA Operator panel before logging.',
            ],
            $session?->band_id !== null && (int) $this->band_id !== (int) $session->band_id => [
                'band_id',
                'The band does not match this operating session.',
            ],
            $session !== null && ($bandRestriction = $this->bandRestriction($session)) !== null => [
                'band_id',
                $bandRestriction,
            ],
            $session?->mode_id !== null && (int) $this->mode_id !== (int) $session->mode_id => [
                'mode_id',
                'The mode does not match this operating session.',
            ],
            $this->frequency_hz !== null && $session?->band !== null
                && ! app(BandResolverService::class)->bandContainsFrequencyHz($session->band, (int) $this->frequency_hz) => [
                    'frequency_hz',
                    AdvancedContactFields::formatMhz((int) $this->frequency_hz)." MHz is not on {$session->band->name}.",
                ],
            default => null,
        };
    }

    /**
     * Why the session's station can't log on this contact's band, or null.
     */
    private function bandRestriction(OperatingSession $session): ?string
    {
        $band = Band::find($this->band_id);

        return $band === null ? null : $band->restrictionMessageAt($session->station);
    }

    /**
     * Reject a class letter the event's rulebook does not define.
     */
    private function validateClassForEvent(Validator $validator, ?OperatingSession $session): void
    {
        $eventTypeId = $session?->station->eventConfiguration?->event?->event_type_id;

        if ($eventTypeId === null) {
            return;
        }

        $classCode = strtoupper(substr((string) $this->exchange_class, -1));

        $definedClasses = OperatingClass::query()
            ->where('event_type_id', $eventTypeId)
            ->pluck('code')
            ->map(fn (string $code): string => strtoupper($code))
            ->all();

        if ($definedClasses !== [] && ! in_array($classCode, $definedClasses, true)) {
            $validator->errors()->add(
                'exchange_class',
                "Class {$classCode} is not valid for this event.",
            );
        }
    }

    /**
     * Whether the contact names a GOTA operator: a linked user, or a first and last name.
     */
    private function hasGotaOperator(): bool
    {
        return $this->filled('gota_operator_user_id')
            || ($this->filled('gota_operator_first_name') && $this->filled('gota_operator_last_name'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'uuid.required' => 'A client UUID is required for idempotent sync',
            'operating_session_id.exists' => 'This operating session has ended.',
            'callsign.required' => 'A callsign is required',
            'callsign.regex' => 'The callsign must contain only letters, numbers, and forward slashes',
            'exchange_class.required' => 'The exchange class is required',
            'exchange_class.regex' => 'The exchange class must be a number followed by a valid class letter (e.g. 3A, 2M)',
            ...AdvancedContactFields::messages(),
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'uuid' => 'UUID',
            'operating_session_id' => 'operating session',
            'band_id' => 'band',
            'mode_id' => 'mode',
            'section_id' => 'section',
            'power_watts' => 'power',
            'qso_time' => 'QSO time',
            'gota_operator_first_name' => 'GOTA operator first name',
            'gota_operator_last_name' => 'GOTA operator last name',
            'gota_operator_callsign' => 'GOTA operator callsign',
            'gota_operator_user_id' => 'GOTA operator',
            ...AdvancedContactFields::attributes(),
        ];
    }
}
