<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The professor's daily email (PRD-10 FR-06): how many sales, reviews and questions came in, instead of one email
 * each. Email only — each event is already in the in-app feed.
 */
final class ProfessorDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, int>  $counts  By notification type (`new_sale`, `new_review`, `new_question`); zeros left out.
     */
    public function __construct(public readonly array $counts)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('notifications.digest.subject', [], $this->locale))
            ->greeting(__('notifications.digest.subject', [], $this->locale))
            ->line(__('notifications.digest.intro', [], $this->locale));
        foreach ($this->counts as $type => $count) {
            $mail->line(trans_choice("notifications.digest.{$type}", $count, ['count' => $count], $this->locale));
        }

        return $mail->action(__('notifications.open', [], $this->locale), PlatformNotification::webUrl('/studio'));
    }
}
