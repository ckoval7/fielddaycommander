<?php

// SPDX-FileCopyrightText: Taylor Otwell
// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later AND MIT

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\AuthSecurity;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords, with the stricter
     * minimum length when the account is administrative.
     *
     * @return array<int, Rule|array<mixed>|string|Password>
     */
    protected function passwordRules(?User $user = null): array
    {
        return ['required', 'string', AuthSecurity::passwordRule($user), 'confirmed'];
    }
}
