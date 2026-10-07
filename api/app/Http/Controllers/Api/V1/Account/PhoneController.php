<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Identity\Actions\ChangePhone;
use App\Domain\Identity\Actions\RequestOtp;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Requests\Account\ChangePhoneRequest;
use App\Http\Requests\Account\RequestPhoneChangeOtpRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * Change the account's phone number (PRD-01 FR-09).
 */
final class PhoneController extends Controller
{
    /**
     * Send a code to the new number.
     */
    public function requestOtp(RequestPhoneChangeOtpRequest $request, RequestOtp $requestOtp): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $challenge = $requestOtp->handle($request->phone(), OtpPurpose::ChangePhone, $request->ip(), $request->userAgent(), $user);

        return new JsonResponse(['data' => [
            'challenge_id' => $challenge->id,
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_after_seconds' => (int) config('yekkola.auth.otp_resend_after_seconds'),
        ]], 202);
    }

    /**
     * Confirm the new number. Every other session and device is signed out.
     */
    public function update(ChangePhoneRequest $request, ChangePhone $changePhone): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        $user = $changePhone->handle(
            $user,
            $request->phone(),
            (string) $request->input('code'),
            $token instanceof PersonalAccessToken ? $token->id : null,
        );

        // Keep the current web session alive under the new epoch.
        if (! $token instanceof PersonalAccessToken && $request->hasSession()) {
            $request->session()->put(EnsureAccountActive::SESSION_EPOCH_KEY, $user->auth_epoch);
        }

        return new UserResource($user->load('province'));
    }
}
