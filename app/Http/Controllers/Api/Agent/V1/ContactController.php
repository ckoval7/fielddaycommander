<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers\Api\Agent\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Requests\StoreAgentContactsRequest;
use App\Services\AgentContactIngestService;
use Illuminate\Http\JsonResponse;

class ContactController extends Controller
{
    public function store(StoreAgentContactsRequest $request, AgentContactIngestService $ingest): JsonResponse
    {
        return response()->json([
            'results' => $ingest->ingest(
                $request->attributes->get(AuthenticateAgent::REQUEST_ATTRIBUTE),
                $request->validated('contacts'),
            ),
        ]);
    }
}
