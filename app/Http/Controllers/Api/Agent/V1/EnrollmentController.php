<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers\Api\Agent\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnrollAgentRequest;
use App\Services\AgentEnrollmentService;
use Illuminate\Http\JsonResponse;

class EnrollmentController extends Controller
{
    public function __invoke(EnrollAgentRequest $request, AgentEnrollmentService $enrollment): JsonResponse
    {
        $enrolled = $enrollment->redeem($request->validated('code'), $request->validated('agent_version'));

        if ($enrolled === null) {
            return response()->json([
                'code' => 'invalid_code',
                'message' => 'This enrollment code is invalid, expired, used, or revoked.',
            ], 422);
        }

        return response()->json([
            'token' => $enrolled['token'],
            'agent' => ['id' => $enrolled['agent']->id, 'name' => $enrolled['agent']->name],
        ], 201);
    }
}
