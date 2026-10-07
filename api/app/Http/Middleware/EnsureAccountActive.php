<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `auth:sanctum` on every signed-in route (alias `active`):
 *
 * - Web sessions signed in before the user's last "sign out everywhere" (auth_epoch) are ended.
 * - Banned users are signed out and refused (PRD-01 FR-15).
 * - Suspended users may only read their profile and sign out; everything else is refused.
 * - Touches last_seen_at / device last_active_at at most every 5 minutes.
 */
final class EnsureAccountActive
{
    public const SESSION_EPOCH_KEY = 'auth_epoch';

    /** Routes a suspended user may still use. */
    private const SUSPENDED_ALLOWED = ['me.show', 'auth.logout'];

    private const TOUCH_EVERY_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        $isSession = ! $token instanceof PersonalAccessToken;

        if ($isSession && $request->hasSession() && (int) $request->session()->get(self::SESSION_EPOCH_KEY, -1) !== $user->auth_epoch) {
            $this->endSession($request);

            throw IdentityException::sessionExpired();
        }

        if ($user->isBanned()) {
            $isSession ? $this->endSession($request) : $token->delete();

            throw IdentityException::accountBanned();
        }

        if ($user->isSuspended() && ! in_array($request->route()?->getName(), self::SUSPENDED_ALLOWED, true)) {
            throw IdentityException::accountSuspended();
        }

        $this->touch($user, $isSession ? null : $token);

        return $next($request);
    }

    private function endSession(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    private function touch(User $user, ?PersonalAccessToken $token): void
    {
        if ($user->last_seen_at === null || $user->last_seen_at->diffInSeconds(now()) > self::TOUCH_EVERY_SECONDS) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        $device = $token?->device;
        if ($device instanceof Device && ($device->last_active_at === null || $device->last_active_at->diffInSeconds(now()) > self::TOUCH_EVERY_SECONDS)) {
            $device->forceFill(['last_active_at' => now()])->saveQuietly();
        }
    }
}
