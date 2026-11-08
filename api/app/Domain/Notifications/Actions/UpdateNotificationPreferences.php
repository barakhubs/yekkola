<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationPreference;
use Illuminate\Database\ConnectionInterface;

/** Saves a user's channel switches (PRD-10 FR-05); which ones may be switched is checked by the request. */
final class UpdateNotificationPreferences
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @param  list<array{type: string, channel: string, enabled: bool}>  $items
     */
    public function handle(User $user, array $items): void
    {
        $this->db->transaction(function () use ($user, $items): void {
            foreach ($items as $item) {
                NotificationPreference::query()->updateOrCreate(
                    ['user_id' => $user->id, 'type' => $item['type'], 'channel' => $item['channel']],
                    ['enabled' => (bool) $item['enabled']],
                );
            }
        });
    }
}
