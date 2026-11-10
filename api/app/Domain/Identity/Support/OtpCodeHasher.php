<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

/**
 * OTP codes are never stored: only an HMAC keyed with the app key and bound to the phone number.
 * Outside production a fixed test code may replace the random one (`OTP_TEST_CODE`, project-context: Identity),
 * so local and staging sign-in works without an SMS provider; production refuses to boot with one set.
 */
final class OtpCodeHasher
{
    public function __construct(private readonly string $key, private readonly ?string $testCode = null) {}

    public function hash(string $phoneE164, string $code): string
    {
        return hash_hmac('sha256', $phoneE164.'|'.$code, $this->key);
    }

    public function matches(string $hash, string $phoneE164, string $code): bool
    {
        return hash_equals($hash, $this->hash($phoneE164, $code));
    }

    public function generate(): string
    {
        if ($this->testCode !== null && preg_match('/^\d{6}$/', $this->testCode) === 1) {
            return $this->testCode;
        }

        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }
}
