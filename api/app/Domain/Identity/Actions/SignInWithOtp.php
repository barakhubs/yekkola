<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\DeviceData;
use App\Domain\Identity\Data\SignInResult;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Events\UserRegistered;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Verifies the code and signs the user in, creating the account on first sign-in (PRD-01 FR-03/06).
 *
 * - Web: the controller starts a session with the returned user.
 * - Mobile ($device given): the device is registered (device limit applies) and a device-bound token is issued.
 *
 * The code is consumed only when everything succeeds, so a device-limit error can be retried with the same code.
 */
final class SignInWithOtp
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly VerifyOtp $verifyOtp,
        private readonly RegisterDevice $registerDevice,
        private readonly Dispatcher $events,
    ) {}

    public function handle(PhoneNumber $phone, string $code, string $locale, ?DeviceData $device = null, ?string $replaceDeviceId = null): SignInResult
    {
        $challenge = $this->verifyOtp->handle($phone, $code, OtpPurpose::Login);

        $result = $this->db->transaction(function () use ($challenge, $phone, $locale, $device, $replaceDeviceId): SignInResult {
            $this->verifyOtp->consume($challenge);

            $user = User::query()->where('phone_e164', $phone->e164)->lockForUpdate()->first();

            if ($user?->isBanned()) {
                return new SignInResult($user, false);
            }

            $isNew = $user === null;
            $user ??= User::query()->create(['phone_e164' => $phone->e164, 'locale' => $locale]);

            if ($isNew) {
                $user->assignRole(Role::Student->value);
            }

            $registered = null;
            $plainTextToken = null;
            if ($device !== null) {
                $registered = $this->registerDevice->handle($user, $device, $replaceDeviceId);
                $plainTextToken = $this->issueToken($user, $registered->id);
            }

            $user->forceFill([
                'phone_verified_at' => $user->phone_verified_at ?? now(),
                'last_login_at' => now(),
            ])->save();

            return new SignInResult($user, $isNew, $registered, $plainTextToken);
        });

        // Banned: the code is spent (committed above) but no session or token is issued.
        if ($result->user->isBanned()) {
            throw IdentityException::accountBanned();
        }

        if ($result->isNewUser) {
            $this->events->dispatch(new UserRegistered($result->user));
        }

        return $result;
    }

    private function issueToken(User $user, string $deviceId): string
    {
        // One token per device: signing in again on the same install replaces its token.
        $user->tokens()->where('device_id', $deviceId)->delete();

        $token = $user->createToken('device', ['*'], now()->addDays((int) config('yekkola.auth.token_ttl_days')));
        $token->accessToken->forceFill(['device_id' => $deviceId])->save();

        return $token->plainTextToken;
    }
}
