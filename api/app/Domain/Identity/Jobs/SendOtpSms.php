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
 * Sends one OTP by SMS. On the high-priority queue: the user is waiting for it.
 */
final class SendOtpSms implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Codes expire in minutes; retrying later than that is pointless. */
    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public readonly string $phoneE164,
        public readonly string $code,
        public readonly string $locale,
    ) {
        $this->onQueue('otp');
    }

    public function handle(SmsSender $sms): void
    {
        $message = Lang::get('auth_sms.otp', [
            'code' => $this->code,
            'minutes' => config('yekkola.auth.otp_ttl_minutes'),
        ], $this->locale);

        $hash = config('yekkola.auth.sms_retriever_hash');
        if (is_string($hash) && $hash !== '') {
            $message .= "\n\n".$hash;
        }

        $sms->send(PhoneNumber::fromString($this->phoneE164), $message);
    }
}
