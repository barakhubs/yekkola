<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Database\ConnectionInterface;

/**
 * Moves an account to a new phone number after the new number confirms an OTP (PRD-01 FR-09).
 * Every other session and token is signed out; the caller's own token can be kept.
 */
final class ChangePhone
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly VerifyOtp $verifyOtp,
        private readonly SignOutEverywhere $signOutEverywhere,
    ) {}

    public function handle(User $user, PhoneNumber $newPhone, string $code, ?string $keepTokenId = null): User
    {
        $challenge = $this->verifyOtp->handle($newPhone, $code, OtpPurpose::ChangePhone, $user->id);

        return $this->db->transaction(function () use ($user, $newPhone, $challenge, $keepTokenId): User {
            $this->verifyOtp->consume($challenge);

            if (User::withTrashed()->where('phone_e164', $newPhone->e164)->whereKeyNot($user->id)->exists()) {
                throw IdentityException::phoneTaken();
            }

            $user->forceFill([
                'phone_e164' => $newPhone->e164,
                'phone_verified_at' => now(),
            ])->save();

            $this->signOutEverywhere->handle($user, $keepTokenId);

            return $user->refresh();
        });
    }
}
