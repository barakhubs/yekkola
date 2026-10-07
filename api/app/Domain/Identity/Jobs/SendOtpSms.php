<?php

declare(strict_types=1);

namespace App\Domain\Identity\Jobs;

use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Integrations\Sms\SmsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Lang;

/**
 * Sends one OTP by SMS, on the high-priority `otp` queue (the user is waiting).
 *
 * The payload carries the plain code, so it is encrypted on the queue and silenced in Horizon
 * (config/horizon.php). One try only: a late retry would deliver a stale or duplicate code —
 * the user can request a new one after the cooldown.
 */
final class SendOtpSms implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

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
