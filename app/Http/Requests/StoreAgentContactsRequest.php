<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Requests;

use App\Services\AgentContactIngestService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Checks the batch's shape. Each contact is validated on its own by
 * AgentContactIngestService, so one bad contact can't hold up the rest.
 */
class StoreAgentContactsRequest extends FormRequest
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
            'contacts' => ['required', 'array', 'list', 'min:1', 'max:'.AgentContactIngestService::MAX_BATCH_SIZE],
            'contacts.*' => ['required', 'array'],
        ];
    }
}
