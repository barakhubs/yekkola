<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Identity\Support\OtpCodeHasher;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\ConnectionInterface;

/**
 * Checks a code against the latest usable challenge (PRD-01 FR-03).
 *
 * Runs in its own transaction with the challenge row locked, so wrong attempts are always counted
 * (even when the caller's work later fails) and concurrent guesses can't exceed the limit.
 * Across challenges, a number gets at most `otp_max_failures_per_day` wrong codes before it is locked
 * (requesting new codes doesn't reset it), which bounds brute force per victim.
 * A correct code is NOT consumed here — callers consume it, with consume(), once their whole operation
 * succeeds (so e.g. a device-limit error lets the user retry with the same code).
 */
final class VerifyOtp
{
    public function __construct(
        private readonly OtpCodeHasher $hasher,
        private readonly ConnectionInterface $db,
        private readonly RateLimiter $limiter,
    ) {}

    public function handle(PhoneNumber $phone, string $code, OtpPurpose $purpose, ?string $userId = null): OtpChallenge
    {
        $failuresKey = 'otp:fail:'.$phone->e164;
        if ($this->limiter->tooManyAttempts($failuresKey, (int) config('yekkola.auth.otp_max_failures_per_day'))) {
            throw IdentityException::otpLocked($this->limiter->availableIn($failuresKey));
        }

        /** @var OtpChallenge|IdentityException $outcome */
        $outcome = $this->db->transaction(function () use ($phone, $code, $purpose, $userId): OtpChallenge|IdentityException {
            $challenge = OtpChallenge::query()
                ->where('phone_e164', $phone->e164)
                ->where('purpose', $purpose)
                ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
                ->whereNull('consumed_at')
                ->latest()
                ->lockForUpdate()
                ->first();

            if ($challenge === null || ! $challenge->isUsable()) {
                return IdentityException::otpExpired();
            }

            $max = (int) config('yekkola.auth.otp_max_attempts');

            if ($this->hasher->matches($challenge->code_hash, $phone->e164, $code)) {
                return $challenge;
            }

            $challenge->increment('attempts');

            if ($challenge->attempts >= $max) {
                $challenge->forceFill(['consumed_at' => now()])->save();

                return IdentityException::otpTooManyAttempts();
            }

            return IdentityException::otpInvalid($max - $challenge->attempts);
        });

        // Throw after commit so the attempt counter is persisted.
        if ($outcome instanceof IdentityException) {
            if (in_array($outcome->errorCode, ['otp.invalid', 'otp.too_many_attempts'], true)) {
                $this->limiter->hit($failuresKey, 86_400);
            }

            throw $outcome;
        }

        return $outcome;
    }

    /**
     * Consume a verified challenge. Call inside the caller's transaction; fails if a concurrent request
     * already used the same code.
     */
    public function consume(OtpChallenge $challenge): void
    {
        $fresh = OtpChallenge::query()->whereKey($challenge->id)->lockForUpdate()->first();

        if ($fresh === null || $fresh->consumed_at !== null) {
            throw IdentityException::otpExpired();
        }

        $fresh->forceFill(['consumed_at' => now()])->save();
    }
}
