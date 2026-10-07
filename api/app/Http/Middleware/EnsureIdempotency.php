<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\Exceptions\IdempotencyException;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Requires an `Idempotency-Key` header and replays the stored response for repeated requests.
 *
 * - Same key + same request   → original response is replayed (header `Idempotent-Replayed: true`).
 * - Same key + other request  → `idempotency.key_reused`.
 * - Same key while the first is still running → `idempotency.in_progress`.
 *
 * Keys are scoped to the authenticated user (session or bearer token), or to the IP for guests, plus the route.
 * Only final outcomes are stored (2xx and definitive 4xx); temporary failures (5xx, 401, 403, 408, 409, 423,
 * 425, 429) are not, so the client can retry them with the same key.
 *
 * This is a fast-path guard. Money-moving tables keep their own unique idempotency column as the real guarantee.
 */
final class EnsureIdempotency
{
    private const RESULT_TTL_SECONDS = 86_400;

    /** Longer than any request can run (gateway calls included), so a slow request is never executed twice. */
    private const PROCESSING_TTL_SECONDS = 600;

    /** Final client errors worth replaying. Other 4xx are temporary and are not stored. */
    private const STORABLE_CLIENT_ERRORS = [400, 404, 410, 422];

    /** Response headers kept for replays. */
    private const REPLAYED_HEADERS = ['Content-Type', 'Content-Language', 'Location'];

    public function __construct(private readonly Cache $cache) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' || strlen($key) > 255) {
            throw IdempotencyException::missingKey();
        }

        // Resolve the user explicitly: the default guard is `web`, which doesn't see bearer tokens.
        $scope = $request->user('sanctum')?->getAuthIdentifier() ?? 'ip:'.$request->ip();
        $cacheKey = 'idempotency:'.hash('sha256', $scope.'|'.$request->method().'|'.$request->path().'|'.$key);
        $fingerprint = hash('sha256', (string) $request->getQueryString().'|'.$request->getContent());

        // Atomic claim (SET NX): only one request with this key runs at a time.
        if (! $this->cache->add($cacheKey, ['state' => 'processing', 'fingerprint' => $fingerprint], self::PROCESSING_TTL_SECONDS)) {
            return $this->fromExisting($this->cache->get($cacheKey), $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->cache->forget($cacheKey);

            throw $e;
        }

        if ($this->isFinal($response->getStatusCode())) {
            $this->cache->put($cacheKey, [
                'state' => 'done',
                'fingerprint' => $fingerprint,
                'status' => $response->getStatusCode(),
                'content' => (string) $response->getContent(),
                'headers' => $this->replayedHeaders($response),
            ], self::RESULT_TTL_SECONDS);
        } else {
            $this->cache->forget($cacheKey);
        }

        return $response;
    }

    private function isFinal(int $status): bool
    {
        return ($status >= 200 && $status < 300) || in_array($status, self::STORABLE_CLIENT_ERRORS, true);
    }

    /**
     * @param  mixed  $stored  Cache entry; may be null if it expired between add() and get().
     */
    private function fromExisting(mixed $stored, string $fingerprint): Response
    {
        if (! is_array($stored)) {
            throw IdempotencyException::inProgress();
        }

        if (! hash_equals((string) $stored['fingerprint'], $fingerprint)) {
            throw IdempotencyException::keyReused();
        }

        if ($stored['state'] !== 'done') {
            throw IdempotencyException::inProgress();
        }

        return new IlluminateResponse(
            $stored['content'],
            $stored['status'],
            [...$stored['headers'], 'Idempotent-Replayed' => 'true'],
        );
    }

    /**
     * @return array<string, string>
     */
    private function replayedHeaders(Response $response): array
    {
        $headers = [];
        foreach (self::REPLAYED_HEADERS as $name) {
            $value = $response->headers->get($name);
            if ($value !== null) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
