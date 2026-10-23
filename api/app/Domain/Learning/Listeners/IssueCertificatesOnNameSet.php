<?php

declare(strict_types=1);

namespace App\Domain\Learning\Listeners;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Jobs\IssuePendingCertificates;

/** A student who completed courses before adding a name gets their certificates once they add one. */
final class IssueCertificatesOnNameSet
{
    public function handle(User $user): void
    {
        if ($user->wasChanged('name') && blank($user->getOriginal('name')) && filled($user->name)) {
            IssuePendingCertificates::dispatch($user->id)->afterCommit();
        }
    }
}
