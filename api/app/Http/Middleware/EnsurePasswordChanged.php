<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Back-office routes: a staff member whose password was set for them (first sign-in, or reset by a staff manager)
 * must choose their own first. `/me` and `/me/password` stay open so the back office can ask for it.
 */
final class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->must_change_password) {
            throw IdentityException::passwordChangeRequired();
        }

        return $next($request);
    }
}
