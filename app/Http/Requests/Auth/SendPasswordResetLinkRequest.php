<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Requests\Auth;

use App\Support\PasswordResetLinks;
use Illuminate\Validation\Validator;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest as FortifySendPasswordResetLinkRequest;

/**
 * Fortify's forgot-password request, refused while APP_URL is unset so no
 * reset link is ever built from a Host header someone else chose.
 */
class SendPasswordResetLinkRequest extends FortifySendPasswordResetLinkRequest
{
    /**
     * Add the APP_URL check after the field rules.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! PasswordResetLinks::selfServiceAvailable()) {
                $validator->errors()->add(Fortify::email(), PasswordResetLinks::unavailableMessage());
            }
        });
    }
}
