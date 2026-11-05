<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Requests;

use App\Services\RelayContactIngestService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Checks the batch's shape. Each contact is validated on its own by
 * RelayContactIngestService, so one bad contact can't hold up the rest.
 */
class StoreRelayContactsRequest extends FormRequest
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
            'contacts' => ['required', 'array', 'list', 'min:1', 'max:'.RelayContactIngestService::MAX_BATCH_SIZE],
            'contacts.*' => ['required', 'array'],
        ];
    }
}
