<?php

namespace App\Actions\Fortify;

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
                'max:255',
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
        ])->validate();

        $user = User::create([
            'call_sign' => $input['call_sign'],
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
            'is_youth' => ! empty($input['is_youth']),
            'is_cpr_aed_trained' => ! empty($input['is_cpr_aed_trained']),
            'account_locked_at' => config('auth-security.registration_mode') === 'approval_required' ? now() : null,
        ]);

        // Assign default "Operator" role to new users
        $user->assignRole('Operator');

        return $user;
    }
}
