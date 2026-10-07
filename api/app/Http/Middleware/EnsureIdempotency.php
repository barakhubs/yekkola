<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\Exceptions\IdempotencyException;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires an `Idempotency-Key` header and replays the stored response for repeated requests.
 *
 * - Same key + same payload  → original response is returned (header `Idempotent-Replayed: true`).
 * - Same key + other payload → `idempotency.key_reused`.
 * - Same key while the first request is still running → `idempotency.in_progress`.
 *
 * Keys are scoped to the caller (user, or IP for guests) and the route. Server errors (5xx) are not stored,
 * so the client can retry them with the same key.
 */
final class EnsureIdempotency
{
    private const TTL_SECONDS = 86_400;

    private const LOCK_SECONDS = 30;

    public function __construct(
        private readonly Cache $cache,
        private readonly LockProvider $locks,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || strlen($key) > 255) {
            throw IdempotencyException::missingKey();
        }

        $scope = $request->user()?->getAuthIdentifier() ?? $request->ip();
        $cacheKey = 'idempotency:'.hash('sha256', $scope.'|'.$request->method().'|'.$request->path().'|'.$key);
        $fingerprint = hash('sha256', $request->getContent());

        $stored = $this->cache->get($cacheKey);
        if (is_array($stored)) {
            return $this->replay($stored, $fingerprint);
        }

        $lock = $this->locks->lock($cacheKey.':lock', self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw IdempotencyException::inProgress();
        }

        try {
            $stored = $this->cache->get($cacheKey);
            if (is_array($stored)) {
                return $this->replay($stored, $fingerprint);
            }

            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                $this->cache->put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'content' => $response->getContent(),
                    'content_type' => $response->headers->get('Content-Type'),
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{fingerprint: string, status: int, content: string|false, content_type: ?string}  $stored
     */
    private function replay(array $stored, string $fingerprint): Response
    {
        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            throw IdempotencyException::keyReused();
        }

        return new IlluminateResponse((string) $stored['content'], $stored['status'], array_filter([
            'Content-Type' => $stored['content_type'],
            'Idempotent-Replayed' => 'true',
        ]));
    }
}
