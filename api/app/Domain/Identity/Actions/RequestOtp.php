<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\ClientContext;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Jobs\SendOtpSms;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OtpCodeHasher;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Integrations\BotChallenge\BotChallenge;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockProvider;
use Psr\Log\LoggerInterface;

/**
 * Creates an OTP challenge and sends the code by SMS (PRD-01 FR-02, FR-10). SMS costs money, so in order:
 *
 * 1. Bot check: required unless the caller is the mobile app (X-Device-Id; app attestation in phase 3).
 * 2. Country allowlist (premium-rate pumping), atomic limits (per phone, per IP, global hourly SMS budget).
 * 3. Cooldown (one code per 60 s) — atomic.
 * 4. Account checks. These never reveal anything: banned numbers and numbers already taken (phone change)
 *    get the same 202 as everyone else, but no SMS is sent.
 */
final class RequestOtp
{
    private const COOLDOWN_PREFIX = 'otp:cooldown:';

    public function __construct(
        private readonly OtpCodeHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly LockProvider $locks,
        private readonly Dispatcher $bus,
        private readonly BotChallenge $botChallenge,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(PhoneNumber $phone, OtpPurpose $purpose, ClientContext $client, ?User $forUser = null): OtpChallenge
    {
        // Your own current number reveals nothing — safe to say so.
        if ($purpose === OtpPurpose::ChangePhone && $forUser?->phone_e164 === $phone->e164) {
            throw IdentityException::phoneUnchanged();
        }

        $this->guardBot($client, $forUser);
        $this->guardCountry($phone);
        $this->guardRateLimits($phone, $client->ip);

        $lock = $this->locks->lock('otp:issue:'.$phone->e164, 10);
        $lock->block(5);

        try {
            $this->guardCooldown($phone, $purpose, $forUser);

            $code = $this->hasher->generate();
            $challenge = $this->createChallenge($phone, $purpose, $forUser, $client, $code);

            if ($this->shouldSend($phone, $purpose, $forUser)) {
                $this->bus->dispatch(new SendOtpSms($phone->e164, $code, $client->locale));
            }

            return $challenge;
        } finally {
            $lock->release();
        }
    }

    private function guardBot(ClientContext $client, ?User $forUser): void
    {
        // Signed-in users (phone change) are already behind auth + rate limits.
        if ($forUser !== null) {
            return;
        }

        if ($client->deviceId !== null && ! $client->isBrowser) {
            return;
        }

        if (! $this->botChallenge->verify((string) $client->botToken, $client->ip)) {
            throw IdentityException::botChallengeFailed();
        }
    }

    private function guardCountry(PhoneNumber $phone): void
    {
        /** @var list<int> $allowed */
        $allowed = config('yekkola.auth.otp_allowed_country_codes');

        if (! in_array($phone->countryCode(), $allowed, true)) {
            throw IdentityException::countryNotSupported();
        }
    }

    /**
     * hit() first, then compare: the increment is atomic, so parallel requests can't all slip under the limit.
     */
    private function guardRateLimits(PhoneNumber $phone, ?string $ip): void
    {
        $limits = [
            'otp:phone:'.$phone->e164 => config('yekkola.auth.otp_per_phone'),
            'otp:ip:'.($ip ?? 'unknown') => config('yekkola.auth.otp_per_ip'),
        ];

        foreach ($limits as $key => $limit) {
            if ($this->limiter->hit($key, (int) $limit['decay_seconds']) > (int) $limit['max']) {
                throw IdentityException::otpRateLimited($this->limiter->availableIn($key));
            }
        }

        // Circuit breaker on total SMS spend.
        $budget = (int) config('yekkola.auth.otp_global_hourly_budget');
        if ($this->limiter->hit('otp:global', 3_600) > $budget) {
            $this->logger->critical('OTP global hourly budget exceeded — SMS sending paused', ['budget' => $budget]);

            throw IdentityException::otpTemporarilyUnavailable($this->limiter->availableIn('otp:global'));
        }
    }

    private function guardCooldown(PhoneNumber $phone, OtpPurpose $purpose, ?User $forUser): void
    {
        $seconds = (int) config('yekkola.auth.otp_resend_after_seconds');
        $key = self::COOLDOWN_PREFIX.$purpose->value.':'.($forUser->id ?? '-').':'.$phone->e164;

        if ($this->limiter->hit($key, $seconds) > 1) {
            throw IdentityException::otpCooldown(max(1, $this->limiter->availableIn($key)));
        }
    }

    private function createChallenge(PhoneNumber $phone, OtpPurpose $purpose, ?User $forUser, ClientContext $client, string $code): OtpChallenge
    {
        // A new code replaces earlier unused ones for the same phone, purpose, and (for phone change) user.
        OtpChallenge::query()
            ->where('phone_e164', $phone->e164)
            ->where('purpose', $purpose)
            ->when($forUser !== null, fn ($q) => $q->where('user_id', $forUser?->id))
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        return OtpChallenge::query()->create([
            'phone_e164' => $phone->e164,
            'purpose' => $purpose,
            'user_id' => $forUser?->id,
            'code_hash' => $this->hasher->hash($phone->e164, $code),
            'expires_at' => now()->addMinutes((int) config('yekkola.auth.otp_ttl_minutes')),
            'ip' => $client->ip,
            'user_agent' => $client->userAgent,
        ]);
    }

    /**
     * Same response either way; only whether an SMS is actually sent differs (no enumeration).
     */
    private function shouldSend(PhoneNumber $phone, OtpPurpose $purpose, ?User $forUser): bool
    {
        $owner = User::query()->where('phone_e164', $phone->e164)->first();

        return match ($purpose) {
            OtpPurpose::Login => ! (bool) $owner?->isBanned(),
            OtpPurpose::ChangePhone => $owner === null,
        };
    }
}
