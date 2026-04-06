<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetupStep3Request extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'timezone' => [
                'required',
                'string',
                'timezone:all',
            ],
            'date_format' => [
                'required',
                'string',
                'in:Y-m-d,m/d/Y,d/m/Y,d.m.Y',
            ],
            'time_format' => [
                'required',
                'string',
                'in:H:i:s,h:i:s A',
            ],
            'contact_email' => [
                'nullable',
                'email',
                'max:255',
            ],
            'organization_name' => [
                'required',
                'string',
                'max:255',
            ],
            'organization_callsign' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[A-Z0-9]{3,10}$/',
            ],
            'organization_email' => [
                'nullable',
                'email',
                'max:255',
            ],
            'organization_phone' => [
                'nullable',
                'string',
                'max:20',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timezone.required' => 'A timezone is required.',
            'timezone.timezone' => 'The selected timezone is invalid.',
            'date_format.required' => 'A date format is required.',
            'date_format.in' => 'The selected date format is invalid.',
            'time_format.required' => 'A time format is required.',
            'time_format.in' => 'The selected time format is invalid.',
            'contact_email.email' => 'The contact email must be a valid email address.',
            'contact_email.max' => 'The contact email must not exceed 255 characters.',
            'organization_name.required' => 'An organization name is required.',
            'organization_name.max' => 'The organization name must not exceed 255 characters.',
            'organization_callsign.regex' => 'The callsign must be 3-10 uppercase letters and numbers.',
            'organization_callsign.max' => 'The callsign must not exceed 20 characters.',
            'organization_email.email' => 'The organization email must be a valid email address.',
            'organization_email.max' => 'The organization email must not exceed 255 characters.',
            'organization_phone.max' => 'The phone number must not exceed 20 characters.',
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
            'timezone' => 'timezone',
            'date_format' => 'date format',
            'time_format' => 'time format',
            'contact_email' => 'contact email',
            'organization_name' => 'organization name',
            'organization_callsign' => 'organization callsign',
            'organization_email' => 'organization email',
            'organization_phone' => 'organization phone',
        ];
    }
}
