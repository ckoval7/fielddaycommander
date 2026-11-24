<?php

// SPDX-FileCopyrightText: Taylor Otwell
// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later AND MIT

namespace App\Actions\Fortify;

use App\Livewire\Interest\InterestForm;
use App\Models\EventInterest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // Normalize callsign to uppercase before validation to ensure
        // case-insensitive uniqueness check
        if (isset($input['call_sign'])) {
            $input['call_sign'] = mb_strtoupper($input['call_sign']);
        }

        Validator::make($input, [
            'call_sign' => [
                'required',
                'string',
                'max:12',
                Rule::unique(User::class)->withoutTrashed(),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class)->withoutTrashed(),
            ],
            'password' => $this->passwordRules(),
            'is_youth' => ['sometimes', 'boolean'],
            'is_cpr_aed_trained' => ['sometimes', 'boolean'],
            'license_class' => ['nullable', 'string', 'in:Technician,General,Advanced,Extra'],
        ])->validate();

        $requiresApproval = config('auth-security.registration_mode') === 'approval_required';

        $user = User::create([
            'call_sign' => $input['call_sign'],
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
            'is_youth' => ! empty($input['is_youth']),
            'is_cpr_aed_trained' => ! empty($input['is_cpr_aed_trained']),
            'license_class' => $input['license_class'] ?? null,
            'account_locked_at' => $requiresApproval ? now() : null,
            'approval_requested_at' => $requiresApproval ? now() : null,
        ]);

        // Assign default "Operator" role to new users
        $user->assignRole('Operator');

        $this->claimInterestResponses($user);

        return $user;
    }

    /**
     * Tie interest-form responses sent under this email to the new account,
     * and drop the details kept for pre-filling this page.
     */
    protected function claimInterestResponses(User $user): void
    {
        EventInterest::whereNull('user_id')
            ->where('email', mb_strtolower($user->email))
            ->update(['user_id' => $user->id]);

        session()->forget(InterestForm::PREFILL_SESSION_KEY);
    }
}
