<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

/**
 * OTP codes are never stored: only an HMAC keyed with the app key and bound to the phone number.
 */
final class OtpCodeHasher
{
    public function __construct(private readonly string $key) {}

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
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }
}
