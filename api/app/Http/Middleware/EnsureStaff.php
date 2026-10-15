<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin API (alias `staff`, after `signed-in`): moderators, admins, super admins only. Individual routes
 * then check a specific permission with `can:<permission>` (PRD-09 §3).
 */
final class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        $staffRoles = array_map(fn (Role $r) => $r->value, array_filter(Role::cases(), fn (Role $r) => $r->isStaff()));

        if (! $user->hasAnyRole($staffRoles)) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
