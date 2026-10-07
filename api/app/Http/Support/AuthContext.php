<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Identity\Data\ClientContext;
use App\Domain\Identity\Models\PersonalAccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Translates HTTP details into what auth actions need, in one place.
 */
final class AuthContext
{
    /** Session key holding when this web session signed in (for step-up checks). */
    public const SESSION_SIGNED_IN_AT = 'signed_in_at';

    public static function client(Request $request): ClientContext
    {
        $deviceId = $request->header('X-Device-Id');

        return new ClientContext(
            isBrowser: EnsureFrontendRequestsAreStateful::fromFrontend($request),
            deviceId: is_string($deviceId) && $deviceId !== '' ? $deviceId : null,
            botToken: $request->input('bot_token'),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            locale: App::getLocale(),
        );
    }

    /** When the current credential was issued: the token's creation, or the session's sign-in. */
    public static function signedInAt(Request $request): ?CarbonImmutable
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return $token->created_at?->toImmutable();
        }

        $at = $request->hasSession() ? $request->session()->get(self::SESSION_SIGNED_IN_AT) : null;

        return is_int($at) ? CarbonImmutable::createFromTimestamp($at) : null;
    }

    public static function currentToken(Request $request): ?PersonalAccessToken
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }
}
