<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\User;

final readonly class UserRegistered
{
    public function __construct(public User $user) {}
}
