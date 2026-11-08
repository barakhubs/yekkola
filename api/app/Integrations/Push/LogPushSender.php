<?php

declare(strict_types=1);

namespace App\Integrations\Push;

use Psr\Log\LoggerInterface;

/**
 * Development/test driver: writes pushes to the log. Tokens starting with `invalid-` behave like an uninstalled
 * app. Refused in production by the boot guard.
 */
final class LogPushSender implements PushSender
{
    private const MAX_RECORDED = 50;

    /** @var list<array{token: string, title: string, body: string, data: array<string, string>}> */
    private array $sent = [];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function send(string $token, string $title, string $body, array $data): bool
    {
        if (str_starts_with($token, 'invalid-')) {
            return false;
        }

        $this->logger->info('Push (log driver)', ['title' => $title, 'body' => $body, 'data' => $data]);
        $this->sent[] = ['token' => $token, 'title' => $title, 'body' => $body, 'data' => $data];
        $this->sent = array_slice($this->sent, -self::MAX_RECORDED);

        return true;
    }

    public function name(): string
    {
        return 'log';
    }

    /**
     * Recent pushes sent by this process — for tests.
     *
     * @return list<array{token: string, title: string, body: string, data: array<string, string>}>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
