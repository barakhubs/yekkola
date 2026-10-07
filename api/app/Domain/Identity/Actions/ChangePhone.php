<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Jobs\SendSmsNotice;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Moves an account to a new phone number after the new number confirms an OTP (PRD-01 FR-09).
 *
 * Takeover protection: only allowed shortly after a fresh sign-in (a stolen old session can't do it),
 * the old number is told by SMS, the change is audited, and every other session, token, and device
 * (with its offline downloads) is signed out — only the caller's own device/session survives.
 */
final class ChangePhone
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly VerifyOtp $verifyOtp,
        private readonly SignOutEverywhere $signOutEverywhere,
        private readonly RevokeDevice $revokeDevice,
        private readonly RecordAudit $recordAudit,
        private readonly Dispatcher $bus,
    ) {}

    /**
     * Phone change requires a sign-in within the last few minutes (session start or token issue time).
     */
    public static function assertRecentSignIn(?CarbonInterface $signedInAt): void
    {
        $minutes = (int) config('yekkola.auth.reauth_minutes');

        if ($signedInAt === null || $signedInAt->lt(now()->subMinutes($minutes))) {
            throw IdentityException::reauthenticationRequired();
        }
    }

    public function handle(User $user, PhoneNumber $newPhone, string $code, ?CarbonInterface $signedInAt, ?string $keepTokenId = null, ?string $keepDeviceId = null): User
    {
        self::assertRecentSignIn($signedInAt);

        $oldPhone = $user->phone;
        $challenge = $this->verifyOtp->handle($newPhone, $code, OtpPurpose::ChangePhone, $user->id);

        try {
            $user = $this->db->transaction(function () use ($user, $newPhone, $oldPhone, $challenge, $keepTokenId, $keepDeviceId): User {
                $this->verifyOtp->consume($challenge);

                if (User::withTrashed()->where('phone_e164', $newPhone->e164)->whereKeyNot($user->id)->exists()) {
                    throw IdentityException::phoneTaken();
                }

                $user->forceFill(['phone_e164' => $newPhone->e164, 'phone_verified_at' => now()])->save();

                Device::query()->where('user_id', $user->id)->active()
                    ->when($keepDeviceId !== null, fn ($q) => $q->whereKeyNot($keepDeviceId))
                    ->get()
                    ->each(fn (Device $device) => $this->revokeDevice->handle($device));

                $this->signOutEverywhere->handle($user, $keepTokenId);

                $this->recordAudit->handle('user.phone_changed', $user, [
                    'before' => $oldPhone->masked(),
                    'after' => $newPhone->masked(),
                ], causer: $user);

                return $user->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw IdentityException::phoneTaken();
        }

        $this->bus->dispatch(new SendSmsNotice($oldPhone->e164, 'auth_sms.phone_changed', $user->locale, [
            'phone' => $newPhone->masked('*'),
        ]));

        return $user;
    }
}
