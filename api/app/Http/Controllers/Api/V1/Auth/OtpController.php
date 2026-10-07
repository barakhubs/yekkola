<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\RequestOtp;
use App\Domain\Identity\Actions\SignInWithOtp;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\UserResource;
use App\Http\Support\AuthContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Phone + SMS code sign-in (PRD-01). Web apps get a session cookie; mobile apps get a device-bound token.
 */
final class OtpController extends Controller
{
    /**
     * Send a sign-in code by SMS. Always answers the same way, whether or not the number has an account.
     */
    public function request(RequestOtpRequest $request, RequestOtp $requestOtp): JsonResponse
    {
        $challenge = $requestOtp->handle($request->phone(), OtpPurpose::Login, AuthContext::client($request));

        return new JsonResponse(['data' => [
            'challenge_id' => $challenge->id,
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_after_seconds' => (int) config('yekkola.auth.otp_resend_after_seconds'),
        ]], 202);
    }

    /**
     * Verify the code and sign in (creates the account on first sign-in).
     */
    public function verify(VerifyOtpRequest $request, SignInWithOtp $signIn): JsonResponse
    {
        $device = $request->deviceData();
        $client = AuthContext::client($request);

        if ($device === null && ! $client->isBrowser) {
            throw IdentityException::clientUnknown();
        }

        $result = $signIn->handle(
            $request->phone(),
            (string) $request->input('code'),
            $client->locale,
            $device,
            $request->input('replace_device_id'),
        );

        if ($device === null) {
            Auth::guard('web')->login($result->user);
            $request->session()->regenerate();
            $request->session()->put(EnsureAccountActive::SESSION_EPOCH_KEY, $result->user->auth_epoch);
            $request->session()->put(AuthContext::SESSION_SIGNED_IN_AT, now()->getTimestamp());
        }

        $result->user->load('province');

        return new JsonResponse(['data' => array_filter([
            'user' => new UserResource($result->user),
            'is_new_user' => $result->isNewUser,
            'token' => $result->plainTextToken,
            'device' => $result->device ? new DeviceResource($result->device) : null,
        ], fn ($v) => $v !== null)]);
    }
}
