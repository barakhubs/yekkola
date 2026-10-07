<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;

/**
 * Ends every session and token for a user (phone change, suspension, ban, admin force sign-out).
 *
 * Tokens are deleted. Web sessions are invalidated lazily: bumping `auth_epoch` makes every existing
 * session fail its next request (EnsureAccountActive compares the epoch stored at sign-in).
 * Pass $keepTokenId to keep the caller's own token (e.g. the device that changed the phone).
 */
final class SignOutEverywhere
{
    public function handle(User $user, ?string $keepTokenId = null): void
    {
        $user->tokens()
            ->when($keepTokenId !== null, fn ($q) => $q->whereKeyNot($keepTokenId))
            ->delete();

        $user->increment('auth_epoch');
    }
}
