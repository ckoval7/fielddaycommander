<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id)->whereNull('deleted_at'),
            ],
            'license_class' => ['nullable', 'string', 'in:Technician,General,Advanced,Extra'],
            'preferred_timezone' => ['nullable', 'string', 'timezone:all'],
            'notification_preferences' => ['nullable', 'array'],
            'notification_preferences.event_notifications' => ['boolean'],
            'notification_preferences.system_announcements' => ['boolean'],
            'notification_preferences.categories' => ['nullable', 'array'],
            'notification_preferences.categories.*' => ['boolean'],
            'is_cpr_aed_trained' => ['sometimes', 'boolean'],
        ])->validateWithBag('updateProfileInformation');

        if ($input['email'] !== $user->email &&
            $user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill([
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'email' => $input['email'],
                'license_class' => $input['license_class'] ?? null,
                'preferred_timezone' => $input['preferred_timezone'] ?? null,
                'notification_preferences' => $input['notification_preferences'] ?? null,
                'is_cpr_aed_trained' => ! empty($input['is_cpr_aed_trained']),
            ])->save();
        }
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'email' => $input['email'],
            'email_verified_at' => null,
            'license_class' => $input['license_class'] ?? null,
            'preferred_timezone' => $input['preferred_timezone'] ?? null,
            'notification_preferences' => $input['notification_preferences'] ?? null,
            'is_cpr_aed_trained' => ! empty($input['is_cpr_aed_trained']),
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
