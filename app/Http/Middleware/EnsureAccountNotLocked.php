<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Providers\FortifyServiceProvider;
use App\Support\StationMode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountNotLocked
{
    public const string LOCKED_MESSAGE = FortifyServiceProvider::LOCKED_ACCOUNT_MESSAGE;

    /**
     * Query parameter the login page reads to explain why the session ended.
     * The session is invalidated, so a flashed message would not survive
     * Livewire's client-side redirect.
     */
    public const string REASON_PARAMETER = 'account';

    /**
     * End the session of a user whose account was locked after they signed in.
     * Ghost users in a station-mode session are exempt.
     *
     * Livewire and JSON requests get a 419 whose `redirect` points at the
     * login page, so the client navigates there instead of following a
     * redirect inside a fetch. Both paths carry the reason in the URL.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isLocked() || $this->isStationModeGhost($user)) {
            return $next($request);
        }

        $isPendingApproval = $user->isPendingApproval();
        $message = self::messageFor($isPendingApproval ? 'pending' : 'locked');
        $loginUrl = route('login', [self::REASON_PARAMETER => $isPendingApproval ? 'pending' : 'locked']);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() || $request->isJson() || $request->hasHeader('X-Livewire')) {
            return response()->json([
                'message' => $message,
                'redirect' => $loginUrl,
            ], 419);
        }

        return redirect()->to($loginUrl);
    }

    /**
     * The login page message for a reason passed in the query string, or null for an unknown reason.
     */
    public static function messageFor(mixed $reason): ?string
    {
        return match ($reason) {
            'locked' => FortifyServiceProvider::LOCKED_ACCOUNT_MESSAGE,
            'pending' => FortifyServiceProvider::PENDING_APPROVAL_MESSAGE,
            default => null,
        };
    }

    /**
     * Ghost users are created locked so they cannot sign in with a password,
     * but station mode deliberately signs them in on an enrolled browser.
     */
    private function isStationModeGhost(User $user): bool
    {
        return $user->is_ghost && StationMode::isActive();
    }
}
