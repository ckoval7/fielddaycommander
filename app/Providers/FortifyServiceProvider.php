<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
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
        $mode = config('auth-security.registration_mode');
        if (! in_array($mode, self::VALID_REGISTRATION_MODES, true)) {
            throw new \RuntimeException("Invalid REGISTRATION_MODE: '{$mode}'. Must be one of: ".implode(', ', self::VALID_REGISTRATION_MODES));
        }

        $twoFaMode = config('auth-security.2fa_mode');
        if (! in_array($twoFaMode, self::VALID_2FA_MODES, true)) {
            throw new \RuntimeException("Invalid 2FA_MODE: '{$twoFaMode}'. Must be one of: ".implode(', ', self::VALID_2FA_MODES));
        }

        $this->app->bind(
            \Laravel\Fortify\Contracts\RegisterResponse::class,
            function () {
                if (config('auth-security.registration_mode') === 'approval_required') {
                    return new \App\Http\Responses\ApprovalPendingRegisterResponse;
                }

                return new \Laravel\Fortify\Http\Responses\RegisterResponse;
            }
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

        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', $request->email)->first();

            if ($user && Hash::check($request->password, $user->password)) {
                if ($user->isLocked()) {
                    throw ValidationException::withMessages([
                        'email' => ['This account has been locked. Please contact an administrator.'],
                    ]);
                }

                return $user;
            }
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
