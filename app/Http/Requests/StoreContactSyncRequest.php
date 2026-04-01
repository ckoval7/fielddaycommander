<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'received_exchange' => ['required', 'string', 'max:50'],
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'uuid.required' => 'A client UUID is required for idempotent sync',
            'callsign.required' => 'A callsign is required',
            'callsign.regex' => 'The callsign must contain only letters, numbers, and forward slashes',
            'received_exchange.required' => 'The received exchange string is required',
        ];
    }
}
