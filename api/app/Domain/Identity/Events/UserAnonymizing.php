<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\User;

/**
 * Dispatched inside the anonymisation transaction (PRD-01 FR-11). Every domain holding personal data
 * listens and erases its own — synchronously, so a failure rolls the whole anonymisation back.
 * File deletions must be deferred until the transaction commits.
 */
final readonly class UserAnonymizing
{
    public function __construct(public User $user) {}
}
