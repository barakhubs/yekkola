<?php

declare(strict_types=1);

namespace App\Integrations\BotChallenge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Psr\Log\LoggerInterface;

/**
 * Cloudflare Turnstile server-side validation (siteverify).
 * Fails closed: if Cloudflare can't be reached, the check fails and the user retries.
 */
final class TurnstileBotChallenge implements BotChallenge
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly Http $http,
        private readonly LoggerInterface $logger,
        private readonly string $secret,
    ) {}

    public function name(): string
    {
        return 'turnstile';
    }

    public function verify(string $token, ?string $ip): bool
    {
        if ($token === '') {
            return false;
        }

        try {
            $response = $this->http->asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret' => $this->secret,
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (ConnectionException $e) {
            $this->logger->warning('Turnstile unreachable', ['error' => $e->getMessage()]);

            return false;
        }

        return $response->ok() && $response->json('success') === true;
    }
}
