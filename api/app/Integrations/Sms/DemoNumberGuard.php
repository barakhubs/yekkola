<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Domain\Shared\ValueObjects\PhoneNumber;
use Psr\Log\LoggerInterface;

/**
 * Wraps the SMS driver on local and staging: numbers in the demo range (DemoCatalogueSeeder) are never texted,
 * whatever the driver — they may belong to real people. Production has no demo data and no guard.
 */
final readonly class DemoNumberGuard implements SmsSender
{
    public function __construct(private SmsSender $inner, private string $demoPrefix, private LoggerInterface $log) {}

    public function send(PhoneNumber $to, string $message): SmsResult
    {
        if ($this->demoPrefix !== '' && str_starts_with($to->e164, $this->demoPrefix)) {
            $this->log->info('SMS to a demo number suppressed', ['to' => $to->masked()]);

            return new SmsResult('demo-suppressed');
        }

        return $this->inner->send($to, $message);
    }

    public function name(): string
    {
        return $this->inner->name();
    }
}
