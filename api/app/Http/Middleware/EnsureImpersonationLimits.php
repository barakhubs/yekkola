<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Exceptions\IdentityException;
use App\Http\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a super admin views the platform as someone else: past the hour the session goes back to the super admin
 * (and the client is told to reload), and money, security and account changes are refused.
 */
final class EnsureImpersonationLimits
{
    public function __construct(private readonly Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $current = Impersonation::current($request);
        if ($current === null) {
            return $next($request);
        }

        if ($current['expires_at'] <= now()->getTimestamp()) {
            $this->impersonation->stop($request, 'expired');

            throw IdentityException::impersonationEnded();
        }

        if (Impersonation::isForbidden($request->route()?->getName())) {
            throw IdentityException::impersonationForbidden();
        }

        return $next($request);
    }
}
