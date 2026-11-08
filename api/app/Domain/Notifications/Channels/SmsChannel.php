<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\PlatformNotification;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Integrations\Sms\SmsSender;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;

/** Sends a notification's SMS text through `SmsSender` (PRD-10 FR-01): to a user's phone, or to a payer's number. */
final class SmsChannel
{
    public function __construct(private readonly SmsSender $sms) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof PlatformNotification) {
            return;
        }

        $phone = match (true) {
            $notifiable instanceof User => $notifiable->phone_e164,
            $notifiable instanceof AnonymousNotifiable => $notifiable->routeNotificationFor('sms'),
            default => null,
        };
        if (! is_string($phone) || $phone === '') {
            return;
        }

        $this->sms->send(PhoneNumber::fromString($phone), $notification->toSms($notifiable));
    }
}
