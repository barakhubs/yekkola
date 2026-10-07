<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Domain\Identity\Models\Device;
use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Support\Collection;

/**
 * Sign-in and account errors (PRD-01). Each has a stable `code` rendered by the API error envelope.
 * `retry_after` (seconds) in the context becomes a Retry-After header.
 */
final class IdentityException extends DomainException
{
    public static function otpCooldown(int $retryAfter): self
    {
        return new self('otp.cooldown', 429, ['retry_after' => $retryAfter]);
    }

    public static function otpRateLimited(int $retryAfter): self
    {
        return new self('otp.rate_limited', 429, ['retry_after' => $retryAfter]);
    }

    public static function otpExpired(): self
    {
        return new self('otp.expired', 422);
    }

    public static function otpInvalid(int $attemptsRemaining): self
    {
        return new self('otp.invalid', 422, ['attempts_remaining' => $attemptsRemaining]);
    }

    public static function otpTooManyAttempts(): self
    {
        return new self('otp.too_many_attempts', 422);
    }

    public static function accountBanned(): self
    {
        return new self('account.banned', 403);
    }

    public static function accountSuspended(): self
    {
        return new self('account.suspended', 403);
    }

    public static function sessionExpired(): self
    {
        return new self('auth.session_expired', 401);
    }

    /**
     * @param  Collection<int, Device>  $activeDevices
     */
    public static function deviceLimitReached(Collection $activeDevices, int $limit): self
    {
        return new self('device.limit_reached', 409, [
            'limit' => $limit,
            'devices' => $activeDevices->map(fn (Device $d) => [
                'id' => $d->id,
                'platform' => $d->platform->value,
                'name' => $d->name,
                'last_active_at' => $d->last_active_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public static function deviceNotFound(): self
    {
        return new self('device.not_found', 404);
    }

    public static function phoneTaken(): self
    {
        return new self('phone.taken', 422);
    }

    public static function phoneUnchanged(): self
    {
        return new self('phone.unchanged', 422);
    }

    public static function clientUnknown(): self
    {
        return new self('auth.client_unknown', 422);
    }

    public static function botChallengeFailed(): self
    {
        return new self('bot_challenge.failed', 422);
    }

    public static function exportNotReady(): self
    {
        return new self('export.not_ready', 409);
    }

    public static function exportInProgress(): self
    {
        return new self('export.in_progress', 409);
    }
}
