<?php

declare(strict_types=1);

namespace App\Integrations\BotChallenge;

/**
 * Accepts any non-empty token except "fail" (local, tests). Refused in production.
 */
final class FakeBotChallenge implements BotChallenge
{
    public const FAILING_TOKEN = 'fail';

    public function name(): string
    {
        return 'fake';
    }

    public function verify(string $token, ?string $ip): bool
    {
        return $token !== '' && $token !== self::FAILING_TOKEN;
    }
}
