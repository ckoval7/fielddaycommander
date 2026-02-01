<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request for validating user updates.
 *
 * This is a reference implementation following Laravel 12 best practices.
 * The UserManagement Livewire component currently uses inline validation,
 * but this Form Request can be used for future API endpoints or when
 * refactoring to controller-based user management.
 *
 * Note: Password updates are handled separately through a dedicated
 * password reset flow, so no password validation is included here.
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'call_sign' => strtoupper($this->call_sign ?? ''),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'call_sign' => [
                'required',
                'string',
                'max:255',
                'regex:/^[A-Z0-9\/]+$/',
                Rule::unique('users')->ignore($this->route('user'))->whereNull('deleted_at'),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users')->ignore($this->route('user'))->whereNull('deleted_at'),
            ],
            'license_class' => ['nullable', 'in:Technician,General,Advanced,Extra'],
            'role_id' => ['required', 'exists:roles,id'],
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
            'call_sign.required' => 'A call sign is required',
            'call_sign.regex' => 'The call sign must contain only letters, numbers, and forward slashes',
            'call_sign.unique' => 'This call sign is already registered',
            'first_name.required' => 'First name is required',
            'last_name.required' => 'Last name is required',
            'email.required' => 'Email address is required',
            'email.email' => 'Please enter a valid email address',
            'email.unique' => 'This email is already registered',
            'license_class.in' => 'Please select a valid license class',
            'role_id.required' => 'Please select a role for this user',
            'role_id.exists' => 'The selected role does not exist',
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
            'call_sign' => 'call sign',
            'first_name' => 'first name',
            'last_name' => 'last name',
            'email' => 'email address',
            'license_class' => 'license class',
            'role_id' => 'role',
        ];
    }
}
