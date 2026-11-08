<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Identity\Events\UserAnonymizing;
use App\Domain\Notifications\Models\NotificationPreference;

/**
 * Account deletion (PRD-01 FR-11): the in-app feed (warnings, question titles, payout digits) and preferences go
 * with the account.
 */
final class EraseNotificationData
{
    public function handle(UserAnonymizing $event): void
    {
        $event->user->notifications()->delete();
        NotificationPreference::query()->where('user_id', $event->user->id)->delete();
    }
}
