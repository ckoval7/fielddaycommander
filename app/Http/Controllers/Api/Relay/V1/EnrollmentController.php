<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers\Api\Relay\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnrollRelayRequest;
use App\Services\RelayEnrollmentService;
use Illuminate\Http\JsonResponse;

class EnrollmentController extends Controller
{
    public function __invoke(EnrollRelayRequest $request, RelayEnrollmentService $enrollment): JsonResponse
    {
        $enrolled = $enrollment->redeem($request->validated('code'), $request->validated('relay_version'));

        if ($enrolled === null) {
            return response()->json([
                'code' => 'invalid_code',
                'message' => 'This enrollment code is invalid, expired, used, or revoked.',
            ], 422);
        }

        return response()->json([
            'token' => $enrolled['token'],
            'relay' => ['id' => $enrolled['relay']->id, 'name' => $enrolled['relay']->name],
        ], 201);
    }
}
