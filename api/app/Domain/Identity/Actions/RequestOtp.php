<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Jobs\SendOtpSms;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OtpCodeHasher;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\App;

/**
 * Creates an OTP challenge and sends the code by SMS (PRD-01 FR-02, FR-10).
 *
 * Limits: one code per phone per 60 s, 3 per phone per 10 min, 10 per IP per hour.
 * A new code replaces any earlier unused code for the same phone and purpose.
 */
final class RequestOtp
{
    public function __construct(
        private readonly OtpCodeHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly Dispatcher $bus,
    ) {}

    public function handle(PhoneNumber $phone, OtpPurpose $purpose, ?string $ip, ?string $userAgent = null, ?User $forUser = null): OtpChallenge
    {
        $this->guardAccount($phone, $purpose, $forUser);
        $this->guardCooldown($phone, $purpose);
        $this->guardRateLimits($phone, $ip);

        $code = $this->hasher->generate();

        OtpChallenge::query()
            ->where('phone_e164', $phone->e164)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $challenge = OtpChallenge::query()->create([
            'phone_e164' => $phone->e164,
            'purpose' => $purpose,
            'user_id' => $forUser?->id,
            'code_hash' => $this->hasher->hash($phone->e164, $code),
            'expires_at' => now()->addMinutes((int) config('yekkola.auth.otp_ttl_minutes')),
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);

        $this->bus->dispatch(new SendOtpSms($phone->e164, $code, App::getLocale()));

        return $challenge;
    }

    private function guardAccount(PhoneNumber $phone, OtpPurpose $purpose, ?User $forUser): void
    {
        $owner = User::query()->where('phone_e164', $phone->e164)->first();

        if ($purpose === OtpPurpose::Login && $owner?->isBanned()) {
            throw IdentityException::accountBanned();
        }

        if ($purpose === OtpPurpose::ChangePhone) {
            if ($forUser !== null && $forUser->phone_e164 === $phone->e164) {
                throw IdentityException::phoneUnchanged();
            }
            if ($owner !== null) {
                throw IdentityException::phoneTaken();
            }
        }
    }

    private function guardCooldown(PhoneNumber $phone, OtpPurpose $purpose): void
    {
        $latest = OtpChallenge::query()
            ->where('phone_e164', $phone->e164)
            ->where('purpose', $purpose)
            ->latest()
            ->first();

        $cooldown = (int) config('yekkola.auth.otp_resend_after_seconds');
        if ($latest !== null && $latest->created_at->diffInSeconds(now()) < $cooldown) {
            throw IdentityException::otpCooldown(max(1, $cooldown - (int) $latest->created_at->diffInSeconds(now())));
        }
    }

    private function guardRateLimits(PhoneNumber $phone, ?string $ip): void
    {
        $limits = [
            'otp:phone:'.$phone->e164 => config('yekkola.auth.otp_per_phone'),
            'otp:ip:'.($ip ?? 'unknown') => config('yekkola.auth.otp_per_ip'),
        ];

        foreach ($limits as $key => $limit) {
            if ($this->limiter->tooManyAttempts($key, (int) $limit['max'])) {
                throw IdentityException::otpRateLimited($this->limiter->availableIn($key));
            }
        }

        foreach ($limits as $key => $limit) {
            $this->limiter->hit($key, (int) $limit['decay_seconds']);
        }
    }
}
