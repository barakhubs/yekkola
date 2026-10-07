<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Identity\Actions\ChangePhone;
use App\Domain\Identity\Actions\RequestOtp;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Requests\Account\ChangePhoneRequest;
use App\Http\Requests\Account\RequestPhoneChangeOtpRequest;
use App\Http\Resources\UserResource;
use App\Http\Support\AuthContext;
use Illuminate\Http\JsonResponse;

/**
 * Change the account's phone number (PRD-01 FR-09). Requires a recent sign-in.
 */
final class PhoneController extends Controller
{
    /**
     * Send a code to the new number. Answers the same whether or not the number is free.
     */
    public function requestOtp(RequestPhoneChangeOtpRequest $request, RequestOtp $requestOtp): JsonResponse
    {
        ChangePhone::assertRecentSignIn(AuthContext::signedInAt($request));

        /** @var User $user */
        $user = $request->user();

        $challenge = $requestOtp->handle($request->phone(), OtpPurpose::ChangePhone, AuthContext::client($request), $user);

        return new JsonResponse(['data' => [
            'challenge_id' => $challenge->id,
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_after_seconds' => (int) config('yekkola.auth.otp_resend_after_seconds'),
        ]], 202);
    }

    /**
     * Confirm the new number. Every other session and device is signed out; the old number is notified.
     */
    public function update(ChangePhoneRequest $request, ChangePhone $changePhone): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $token = AuthContext::currentToken($request);

        $user = $changePhone->handle(
            $user,
            $request->phone(),
            (string) $request->input('code'),
            AuthContext::signedInAt($request),
            $token?->id,
            $token?->device_id,
        );

        // Keep the current web session alive under the new epoch.
        if ($token === null && $request->hasSession()) {
            $request->session()->put(EnsureAccountActive::SESSION_EPOCH_KEY, $user->auth_epoch);
        }

        return new UserResource($user->load('province'));
    }
}
