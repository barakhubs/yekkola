<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Development/test driver: writes messages (including OTP codes) to the log instead of sending them.
 * Refused in production by the integration boot guard.
 */
final class LogSmsSender implements SmsSender
{
    /** @var list<array{to: string, message: string, id: string}> */
    private array $sent = [];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function send(PhoneNumber $to, string $message): SmsResult
    {
        $id = 'log-'.Str::ulid();

        $this->logger->info('SMS (log driver)', ['to' => $to->e164, 'message' => $message, 'id' => $id]);
        $this->sent[] = ['to' => $to->e164, 'message' => $message, 'id' => $id];

        return new SmsResult($id);
    }

    public function name(): string
    {
        return 'log';
    }

    /**
     * Messages sent during this process — for tests.
     *
     * @return list<array{to: string, message: string, id: string}>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
