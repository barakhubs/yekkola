<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Console;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\ProfessorDigest;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;

/**
 * Daily professor digest (PRD-10 FR-06): counts the last 24 hours of sales, reviews and questions from the in-app
 * feed and emails professors who have an email address, leaving out the events they turned email off for. Run
 * twice on the same day, it sends nothing more.
 */
final class SendProfessorDigest extends Command
{
    protected $signature = 'notifications:send-digest';

    protected $description = 'Email professors a daily summary of their sales, reviews and questions';

    public function handle(Cache $cache): int
    {
        $types = array_values(array_map(fn (NotificationType $t) => $t->value, array_filter(NotificationType::cases(), fn (NotificationType $t) => $t->inDigest())));
        $sent = 0;

        DB::table('notifications')
            ->where('notifiable_type', (new User)->getMorphClass())
            ->where('created_at', '>=', now()->subDay())
            ->whereIn(DB::raw("data->>'type'"), $types)
            ->groupBy('notifiable_id', DB::raw("data->>'type'"))
            ->select('notifiable_id', DB::raw("data->>'type' as type"), DB::raw('count(*) as total'))
            ->orderBy('notifiable_id')
            ->get()
            ->groupBy('notifiable_id')
            ->each(function ($rows, string $userId) use ($cache, &$sent): void {
                $user = User::query()->find($userId);
                if ($user === null || $user->email === null || $user->email === '' || $user->isAnonymised()) {
                    return;
                }

                $off = NotificationPreference::query()->where('user_id', $userId)->where('channel', NotificationChannel::Email)
                    ->where('enabled', false)->pluck('type')->map(fn ($t) => $t instanceof NotificationType ? $t->value : (string) $t)->all();
                $counts = [];
                foreach ($rows as $row) {
                    if (! in_array($row->type, $off, true)) {
                        $counts[(string) $row->type] = (int) $row->total;
                    }
                }
                if ($counts === [] || ! $cache->add("digest:{$userId}:".now()->toDateString(), true, now()->addDays(2))) {
                    return;
                }

                $user->notify((new ProfessorDigest($counts))->locale($user->locale));
                $sent++;
            });

        $this->info("Digests sent: {$sent}");

        return self::SUCCESS;
    }
}
