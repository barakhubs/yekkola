<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Channels\PushChannel;
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
        /** Which app `link` belongs to: `web`, or `admin` for staff alerts. */
        public readonly string $app = 'web',
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
                NotificationChannel::Email => $notifiable->email !== null && $notifiable->email !== '' && ! $this->type->inDigest() ? 'mail' : null,
                NotificationChannel::Sms => SmsChannel::class,
                NotificationChannel::Push => PushChannel::class,
            };
        }

        return array_values(array_filter($via));
    }

    /**
     * @return array{type: string, key: string, params: array<string, string>, link: string|null, app: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['type' => $this->type->value, 'key' => $this->key, 'params' => $this->params, 'link' => $this->link, 'app' => $this->app];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Params can come from users (course titles…): no Markdown links or formatting in the email.
        $mail = (new MailMessage)
            ->subject($this->text('title'))
            ->greeting($this->text('title', escape: true))
            ->line($this->text('body', escape: true));

        return $this->link === null ? $mail : $mail->action(__('notifications.open', [], $this->locale), self::webUrl($this->link, $this->app));
    }

    public function toSms(object $notifiable): string
    {
        return $this->text('sms');
    }

    public function text(string $part, bool $escape = false): string
    {
        $params = $escape
            ? array_map(fn (string $v) => (string) preg_replace('/([\\\\`*_{}\[\]()<>#+!|~-])/', '\\\\$1', $v), $this->params)
            : $this->params;

        return (string) __("notifications.{$this->key}.{$part}", $params, $this->locale);
    }

    public static function webUrl(string $link, string $app = 'web'): string
    {
        return rtrim((string) config($app === 'admin' ? 'yekkola.admin_url' : 'yekkola.web_url'), '/').$link;
    }
}
