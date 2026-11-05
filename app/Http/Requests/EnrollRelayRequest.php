<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnrollRelayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
            'relay_version' => ['nullable', 'string', 'regex:/^[0-9A-Za-z.+_-]{1,32}$/'],
        ];
    }
}
