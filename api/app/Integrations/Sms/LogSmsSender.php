<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Development/test driver: writes messages (including OTP codes) to the log instead of sending them.
 * The number is masked in the log — testers find their code by the last three digits.
 * Numbers ending in 0000 simulate a provider rejection. Refused in production by the boot guard.
 */
final class LogSmsSender implements SmsSender
{
    /** Keep only recent messages so long-running workers don't grow without bound. */
    private const MAX_RECORDED = 50;

    /** @var list<array{to: string, message: string, id: string}> */
    private array $sent = [];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function send(PhoneNumber $to, string $message): SmsResult
    {
        if (str_ends_with($to->e164, '0000')) {
            throw new SmsDeliveryFailed('Simulated provider rejection.');
        }

        $id = 'log-'.Str::ulid();

        $this->logger->info('SMS (log driver)', ['to' => $to->masked(), 'message' => $message, 'id' => $id]);

        $this->sent[] = ['to' => $to->e164, 'message' => $message, 'id' => $id];
        $this->sent = array_slice($this->sent, -self::MAX_RECORDED);

        return new SmsResult($id);
    }

    public function name(): string
    {
        return 'log';
    }

    /**
     * Recent messages sent by this process — for tests.
     *
     * @return list<array{to: string, message: string, id: string}>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
