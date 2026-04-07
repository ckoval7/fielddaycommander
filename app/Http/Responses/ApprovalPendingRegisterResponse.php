<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

class ApprovalPendingRegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): Response
    {
        // Log the user out since they were auto-logged in by Fortify
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('registration.pending');
    }
}
