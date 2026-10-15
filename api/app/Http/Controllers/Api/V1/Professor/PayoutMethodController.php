<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Identity\Actions\RequestOtp;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Models\User;
use App\Domain\Professors\Actions\ChangePayoutMethod;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professor\ChangePayoutMethodRequest;
use App\Http\Resources\ProfessorProfileResource;
use App\Http\Support\AuthContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where payouts are sent (PRD-02 FR-09): request a code to the account's number, then confirm the change.
 */
final class PayoutMethodController extends Controller
{
    public function requestOtp(Request $request, RequestOtp $requestOtp): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $challenge = $requestOtp->handle($user->phone, OtpPurpose::PayoutMethod, AuthContext::client($request), $user);

        return new JsonResponse(['data' => [
            'challenge_id' => $challenge->id,
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'resend_after_seconds' => (int) config('yekkola.auth.otp_resend_after_seconds'),
        ]], 202);
    }

    public function update(ChangePayoutMethodRequest $request, ChangePayoutMethod $change): ProfessorProfileResource
    {
        /** @var ProfessorProfile $profile */
        $profile = $request->attributes->get('professor_profile');

        $profile = $change->handle(
            $profile,
            $request->rail(),
            $request->phone(),
            (string) $request->input('name'),
            (string) $request->input('code'),
        );

        return new ProfessorProfileResource($profile->load('province'));
    }
}
