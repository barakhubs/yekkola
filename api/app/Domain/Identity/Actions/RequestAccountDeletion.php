<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;

/**
 * Starts the deletion grace period (PRD-01 FR-11). The account keeps working until it is anonymised by
 * `identity:purge-deleted-accounts` after the grace period; the user can cancel before then.
 */
final class RequestAccountDeletion
{
    public function handle(User $user): User
    {
        if ($user->deletion_requested_at === null) {
            $user->forceFill(['deletion_requested_at' => now()])->save();
        }

        return $user;
    }

    public function cancel(User $user): User
    {
        $user->forceFill(['deletion_requested_at' => null])->save();

        return $user;
    }
}
