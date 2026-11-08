<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Channels\SmsChannel;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\NotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification from the catalogue (PRD-10), sent from the queue after the change that caused it is committed.
 * Its text is `lang/{locale}/notifications.php` → `{key}.title|body|sms` with `params`; the in-app feed stores the
 * key and params and renders them in the reader's language. `link` is a web path the apps open (deep link).
 */
final class PlatformNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $key  Translation key, `{type}` or `{type}.{variant}` (e.g. `refund_decided.approved`).
     * @param  array<string, string>  $params
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly string $key,
        public readonly array $params = [],
        public readonly ?string $link = null,
    ) {
        $this->afterCommit();
        $this->onQueue('notifications');
    }

    /**
     * Catalogue channels, minus those turned off (non-critical only, BR-02) and those the recipient can't receive.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return $notifiable->routeNotificationFor('sms') !== null ? [SmsChannel::class] : [];
        }
        if (! $notifiable instanceof User || $notifiable->isAnonymised()) {
            return [];
        }

        $off = $this->type->canDisable() ? NotificationPreference::disabledChannels($notifiable->id, $this->type) : [];
        $via = [];
        foreach ($this->type->channels() as $channel) {
            if (in_array($channel, $off, true)) {
                continue;
            }
            $via[] = match ($channel) {
                NotificationChannel::InApp => 'database',
                NotificationChannel::Email => $notifiable->email !== null && $notifiable->email !== '' ? 'mail' : null,
                NotificationChannel::Sms => SmsChannel::class,
                NotificationChannel::Push => null, // FCM channel: next step of 1.12
            };
        }

        return array_values(array_filter($via));
    }

    /**
     * @return array{type: string, key: string, params: array<string, string>, link: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return ['type' => $this->type->value, 'key' => $this->key, 'params' => $this->params, 'link' => $this->link];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->text('title'))
            ->greeting($this->text('title'))
            ->line($this->text('body'));

        return $this->link === null ? $mail : $mail->action(__('notifications.open', [], $this->locale), self::webUrl($this->link));
    }

    public function toSms(object $notifiable): string
    {
        return $this->text('sms');
    }

    public function text(string $part): string
    {
        return (string) __("notifications.{$this->key}.{$part}", $this->params, $this->locale);
    }

    public static function webUrl(string $link): string
    {
        return rtrim((string) config('yekkola.web_url'), '/').$link;
    }
}
