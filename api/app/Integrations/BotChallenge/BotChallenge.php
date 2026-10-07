<?php

declare(strict_types=1);

namespace App\Integrations\BotChallenge;

/**
 * Human check on the web OTP request (PRD-01 FR-10) — SMS pumping costs real money.
 * Cloudflare Turnstile in production; `fake` locally and in tests.
 */
interface BotChallenge
{
    public function name(): string;

    /** True when the token from the browser widget is valid for this visitor. */
    public function verify(string $token, ?string $ip): bool;
}
