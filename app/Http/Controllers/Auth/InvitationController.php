<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\UserInvitation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class InvitationController extends Controller
{
    /**
     * Display the invitation acceptance form.
     */
    public function show(string $token): View|RedirectResponse
    {
        $invitation = UserInvitation::with('user')
            ->where('token', $token)
            ->first();

        if (! $invitation || ! $invitation->isValid()) {
            return redirect()->route('login')
                ->with('error', 'This invitation is invalid, expired, or already accepted.');
        }

        return view('auth.accept-invitation', [
            'invitation' => $invitation,
            'user' => $invitation->user,
            'token' => $token,
        ]);
    }

    /**
     * Process the invitation acceptance.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = UserInvitation::with('user')
            ->where('token', $token)
            ->first();

        if (! $invitation || ! $invitation->isValid()) {
            return redirect()->route('login')
                ->with('error', 'This invitation is invalid, expired, or already accepted.');
        }

        if ($invitation->user->isLocked()) {
            return redirect()->route('login')
                ->with('error', 'This account is locked. Contact an administrator.');
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $invitation->user;
        $user->update([
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ]);

        $invitation->update([
            'accepted_at' => now(),
        ]);

        Auth::login($user);

        return redirect()->route('dashboard')
            ->with('success', 'Welcome! Your account has been activated.');
    }
}
