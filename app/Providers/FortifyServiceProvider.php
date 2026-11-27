<?php

// SPDX-FileCopyrightText: Taylor Otwell
// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later AND MIT

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Exceptions\InvalidAuthSecurityConfigException;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use App\Http\Responses\ApprovalPendingRegisterResponse;
use App\Models\User;
use App\Services\LoginThrottle;
use App\Support\AuthSecurity;
use App\Support\PasswordResetLinks;
use App\Support\SystemAccount;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\SendPasswordResetLinkRequest as FortifySendPasswordResetLinkRequest;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Shown only after a correct password, to accounts locked by an administrator.
     */
    public const LOCKED_ACCOUNT_MESSAGE = 'This account has been locked. Please contact an administrator.';

    /**
     * Shown only after a correct password, to registrations still awaiting administrator approval.
     */
    public const PENDING_APPROVAL_MESSAGE = 'Your account is awaiting administrator approval. You can sign in once an administrator approves it.';

    /** @var list<string> */
    private const VALID_REGISTRATION_MODES = ['open', 'approval_required', 'email_verification_required', 'disabled'];

    /** @var list<string> */
    private const VALID_2FA_MODES = ['required', 'optional', 'disabled'];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->validateSettings();

        // PASSWORD_MIN_LENGTH for every account; administrative accounts get
        // PASSWORD_ADMIN_MIN_LENGTH wherever the account or its role is known.
        Password::defaults(fn (): Password => AuthSecurity::passwordRule());

        $this->app->bind(
            RegisterResponse::class,
            function () {
                if (config('auth-security.registration_mode') === 'approval_required') {
                    return new ApprovalPendingRegisterResponse;
                }

                return new \Laravel\Fortify\Http\Responses\RegisterResponse;
            }
        );

        // Reset links come from APP_URL, never the request's Host header.
        $this->app->bind(FortifySendPasswordResetLinkRequest::class, SendPasswordResetLinkRequest::class);
        ResetPassword::createUrlUsing(
            fn (User $user, string $token): string => PasswordResetLinks::url($token, $user->getEmailForPasswordReset())
        );

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Register auth views
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn () => view('auth.register'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn ($request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));

        Fortify::authenticateUsing(fn (Request $request): ?User => $this->authenticate($request));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }

    /**
     * Refuse to boot with a mode or method that isn't one of the documented values.
     */
    private function validateSettings(): void
    {
        $settings = [
            'REGISTRATION_MODE' => ['auth-security.registration_mode', self::VALID_REGISTRATION_MODES],
            '2FA_MODE' => ['auth-security.2fa_mode', self::VALID_2FA_MODES],
            'PASSWORD_RESET_METHOD' => ['auth-security.password_reset_method', AuthSecurity::RESET_METHODS],
            'FAILED_LOGIN_STRATEGY' => ['auth-security.failed_login_strategy', AuthSecurity::FAILED_LOGIN_STRATEGIES],
        ];

        foreach ($settings as $name => [$key, $allowed]) {
            $value = config($key);

            if (! in_array($value, $allowed, true)) {
                throw new InvalidAuthSecurityConfigException("Invalid {$name}: '{$value}'. Must be one of: ".implode(', ', $allowed));
            }
        }
    }

    /**
     * Check a sign-in, applying the per-account failed-login limit (LoginThrottle).
     */
    private function authenticate(Request $request): ?User
    {
        $user = $this->findLoginUser((string) $request->input(Fortify::username()));

        // A locked SYSTEM opens only through a one-time sign-in link.
        if (SystemAccount::isLockedAccount($user)) {
            return null;
        }

        $throttle = app(LoginThrottle::class);

        if ($user && ($seconds = $throttle->secondsUntilAvailable($user)) > 0) {
            throw ValidationException::withMessages([
                Fortify::username() => [trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)])],
            ]);
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            if ($user) {
                $throttle->recordFailure($user);
            }

            return null;
        }

        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'email' => [$user->isPendingApproval() ? self::PENDING_APPROVAL_MESSAGE : self::LOCKED_ACCOUNT_MESSAGE],
            ]);
        }

        $throttle->clear($user);

        return $user;
    }

    /**
     * Find the account a sign-in names, by email or by callsign.
     *
     * Anything with an "@" is an email. Anything else is a callsign, which
     * only counts when exactly one active account holds it: callsign
     * uniqueness is enforced by validation, not the database, so an older
     * install could have duplicates, and those must sign in by email.
     */
    private function findLoginUser(string $login): ?User
    {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        if (str_contains($login, '@')) {
            return User::where('email', $login)->first();
        }

        $matches = User::where('call_sign', mb_strtoupper($login))->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
