<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\PlatformNotification;
use App\Integrations\Push\PushSender;
use Illuminate\Cache\RateLimiter;
use Illuminate\Notifications\Notification;

/**
 * Push to every active device of the user that has a token (PRD-10 FR-04). Optional events are capped at
 * `MAX_OPTIONAL_PER_DAY` per user (FR-10); critical ones always go. A dead token is forgotten.
 */
final class PushChannel
{
    public const MAX_OPTIONAL_PER_DAY = 5;

    public function __construct(
        private readonly PushSender $push,
        private readonly RateLimiter $limiter,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof PlatformNotification) {
            return;
        }

        $devices = Device::query()->active()->where('user_id', $notifiable->id)->whereNotNull('push_token')->get();
        if ($devices->isEmpty()) {
            return;
        }

        if ($notification->type->canDisable()) {
            $key = "push-optional:{$notifiable->id}";
            if ($this->limiter->tooManyAttempts($key, self::MAX_OPTIONAL_PER_DAY)) {
                return; // the in-app feed still has it
            }
            $this->limiter->hit($key, 86_400);
        }

        $data = array_filter(['type' => $notification->type->value, 'link' => $notification->link], fn ($v) => $v !== null);
        foreach ($devices as $device) {
            if (! $this->push->send((string) $device->push_token, $notification->text('title'), $notification->text('body'), $data)) {
                $device->forceFill(['push_token' => null])->save();
            }
        }
    }
}
