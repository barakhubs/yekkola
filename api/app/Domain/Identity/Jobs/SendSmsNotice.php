<?php

declare(strict_types=1);

namespace App\Domain\Identity\Jobs;

use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Integrations\Sms\SmsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Lang;

/**
 * Security notice by SMS (e.g. "your number was changed") — translated from `lang/{locale}/auth_sms.php`.
 */
final class SendSmsNotice implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, string>  $replace
     */
    public function __construct(
        public readonly string $phoneE164,
        public readonly string $messageKey,
        public readonly string $locale,
        public readonly array $replace = [],
    ) {
        $this->onQueue('otp');
    }

    public function handle(SmsSender $sms): void
    {
        $sms->send(PhoneNumber::fromString($this->phoneE164), Lang::get($this->messageKey, $this->replace, $this->locale));
    }
}
