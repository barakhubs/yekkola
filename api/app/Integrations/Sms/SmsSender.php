<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Domain\Shared\ValueObjects\PhoneNumber;

/**
 * Sends SMS (OTP codes, critical notifications — PRD-10). Real provider TBD; `LogSmsSender` until then.
 */
interface SmsSender
{
    /**
     * @throws SmsDeliveryFailed when the provider rejects the message
     */
    public function send(PhoneNumber $to, string $message): SmsResult;

    /** Driver name, e.g. "log". */
    public function name(): string;
}
