<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\PersonalAccessToken;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Session\Session;
use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * Ends the current sign-in only: revokes this device's token (the device stays registered),
 * or ends this web session.
 */
final class SignOut
{
    public function __construct(private readonly Auth $auth) {}

    public function handle(?HasAbilities $currentToken, ?Session $session): void
    {
        if ($currentToken instanceof PersonalAccessToken) {
            $currentToken->delete();

            return;
        }

        $this->auth->guard('web')->logout();
        $session?->invalidate();
        $session?->regenerateToken();
    }
}
