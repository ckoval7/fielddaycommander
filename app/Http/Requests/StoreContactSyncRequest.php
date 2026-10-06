<?php

namespace App\Http\Requests;

use App\Models\OperatingClass;
use App\Models\OperatingSession;
use App\Services\ExchangeParserService;
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
            'callsign' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9\/]+$/'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'exchange_class' => ['required', 'string', 'max:5', 'regex:'.ExchangeParserService::EXCHANGE_CLASS_PATTERN],
            'power_watts' => ['required', 'integer', 'min:1'],
            'qso_time' => ['required', 'date'],
            'is_gota_contact' => ['sometimes', 'boolean'],
            'gota_operator_first_name' => ['nullable', 'string', 'max:50'],
            'gota_operator_last_name' => ['nullable', 'string', 'max:50'],
            'gota_operator_callsign' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9\/]*$/'],
            'gota_operator_user_id' => ['nullable', 'integer', 'exists:users,id'],
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
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'uuid.required' => 'A client UUID is required for idempotent sync',
            'callsign.required' => 'A callsign is required',
            'callsign.regex' => 'The callsign must contain only letters, numbers, and forward slashes',
            'exchange_class.required' => 'The exchange class is required',
            'exchange_class.regex' => 'The exchange class must be a number followed by a valid class letter (e.g. 3A, 2M)',
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
        ];
    }
}
