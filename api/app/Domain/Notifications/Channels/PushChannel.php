<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\PlatformNotification;
use App\Integrations\Push\PushSender;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Push to the user's phones that are signed in right now (an active device with a live access token and a push
 * token) — PRD-10 FR-04. A phone signed out, or signed in by someone else, gets nothing. Optional events are capped
 * at `MAX_OPTIONAL_PER_DAY` sent pushes per user (FR-10); critical ones always go. A dead token is forgotten; an
 * error on one phone doesn't stop the others (and isn't retried: a retry would push again to those that got it).
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

        $devices = Device::query()->active()->where('user_id', $notifiable->id)->whereNotNull('push_token')
            ->whereHas('tokens', fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->get();
        $optional = $notification->type->canDisable();
        $key = "push-optional:{$notifiable->id}";
        if ($devices->isEmpty() || ($optional && $this->limiter->tooManyAttempts($key, self::MAX_OPTIONAL_PER_DAY))) {
            return; // the in-app feed still has it
        }

        $data = array_filter(['type' => $notification->type->value, 'link' => $notification->link], fn ($v) => $v !== null);
        $delivered = false;
        foreach ($devices as $device) {
            try {
                if ($this->push->send((string) $device->push_token, $notification->text('title'), $notification->text('body'), $data)) {
                    $delivered = true;
                } else {
                    $device->forceFill(['push_token' => null])->save();
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($optional && $delivered) {
            $this->limiter->hit($key, 86_400);
        }
    }
}
