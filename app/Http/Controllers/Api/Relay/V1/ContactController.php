<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers\Api\Relay\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateRelay;
use App\Http\Requests\StoreRelayContactsRequest;
use App\Services\RelayContactIngestService;
use Illuminate\Http\JsonResponse;

class ContactController extends Controller
{
    public function store(StoreRelayContactsRequest $request, RelayContactIngestService $ingest): JsonResponse
    {
        return response()->json([
            'results' => $ingest->ingest(
                $request->attributes->get(AuthenticateRelay::REQUEST_ATTRIBUTE),
                $request->validated('contacts'),
            ),
        ]);
    }
}
