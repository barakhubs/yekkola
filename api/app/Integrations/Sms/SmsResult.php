<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

final readonly class SmsResult
{
    public function __construct(
        /** Provider's message id, used to match delivery reports. */
        public string $providerMessageId,
    ) {}
}
