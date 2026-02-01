<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetupStep2Request extends FormRequest
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
            'site_name' => [
                'required',
                'string',
                'max:100',
            ],
            'site_tagline' => [
                'nullable',
                'string',
                'max:255',
            ],
            'logo' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,svg',
                'max:2048', // 2MB
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
            'site_name.required' => 'A site name is required.',
            'site_name.max' => 'The site name must not exceed 100 characters.',
            'site_tagline.max' => 'The site tagline must not exceed 255 characters.',
            'logo.image' => 'The site logo must be an image file.',
            'logo.mimes' => 'The site logo must be a JPEG, PNG, or SVG file.',
            'logo.max' => 'The site logo must not exceed 2MB.',
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
            'site_name' => 'site name',
            'site_tagline' => 'site tagline',
            'logo' => 'site logo',
        ];
    }
}
