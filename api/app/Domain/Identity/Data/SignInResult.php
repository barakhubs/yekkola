<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;

final readonly class SignInResult
{
    public function __construct(
        public User $user,
        public bool $isNewUser,
        /** Set for mobile sign-ins. */
        public ?Device $device = null,
        /** Plain-text API token for mobile sign-ins — shown once, never stored. */
        public ?string $plainTextToken = null,
    ) {}
}
