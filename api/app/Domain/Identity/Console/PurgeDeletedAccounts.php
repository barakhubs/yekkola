<?php

declare(strict_types=1);

namespace App\Domain\Identity\Console;

use App\Domain\Identity\Actions\AnonymizeUser;
use App\Domain\Identity\Models\User;
use Illuminate\Console\Command;

/**
 * Anonymises accounts whose deletion grace period has passed (PRD-01 FR-11). Scheduled daily.
 */
final class PurgeDeletedAccounts extends Command
{
    protected $signature = 'identity:purge-deleted-accounts';

    protected $description = 'Anonymise accounts whose deletion grace period has ended';

    public function handle(AnonymizeUser $anonymize): int
    {
        $cutoff = now()->subDays((int) config('yekkola.auth.deletion_grace_days'));
        $count = 0;

        User::query()
            ->whereNotNull('deletion_requested_at')
            ->where('deletion_requested_at', '<=', $cutoff)
            ->each(function (User $user) use ($anonymize, &$count): void {
                $anonymize->handle($user);
                $count++;
            });

        $this->info("Anonymised {$count} account(s).");

        return self::SUCCESS;
    }
}
