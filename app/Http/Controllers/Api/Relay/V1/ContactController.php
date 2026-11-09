<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers\Api\Relay\V1;

use App\DTOs\RelayContactCursor;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateRelay;
use App\Http\Requests\IndexRelayContactsRequest;
use App\Http\Requests\StoreRelayContactsRequest;
use App\Services\RelayContactFeedService;
use App\Services\RelayContactIngestService;
use Illuminate\Http\JsonResponse;

class ContactController extends Controller
{
    public function index(IndexRelayContactsRequest $request, RelayContactFeedService $feed): JsonResponse
    {
        $encodedCursor = $request->validated('cursor');
        $cursor = $encodedCursor === null ? null : RelayContactCursor::decode($encodedCursor);

        if ($encodedCursor !== null && $cursor === null) {
            return response()->json(['code' => 'invalid_cursor', 'message' => 'The cursor is not valid. Start again without one.'], 422);
        }

        return response()->json($feed->changes(
            $request->attributes->get(AuthenticateRelay::REQUEST_ATTRIBUTE),
            $cursor,
            (int) ($request->validated('limit') ?? RelayContactFeedService::DEFAULT_LIMIT),
        ));
    }

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
