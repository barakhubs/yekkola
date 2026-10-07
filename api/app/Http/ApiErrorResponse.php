<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders any exception on API routes as the standard error envelope (docs/architecture.md §4.1):
 *
 *   { "type", "title", "status", "code", "detail"?, "errors"?, ...context }
 *
 * `code` is stable and machine-readable; `title` is translated (lang/{locale}/errors.php).
 * Envelope fields always win over a domain exception's context.
 */
final class ApiErrorResponse
{
    /** Codes for HTTP exceptions, by status. */
    private const HTTP_CODES = [
        400 => 'http.bad_request',
        401 => 'auth.unauthenticated',
        403 => 'auth.forbidden',
        404 => 'resource.not_found',
        405 => 'http.method_not_allowed',
        413 => 'http.payload_too_large',
        419 => 'auth.csrf_mismatch',
        429 => 'rate_limited',
    ];

    public static function render(Throwable $e): JsonResponse
    {
        [$status, $code, $extra] = self::classify($e);

        $body = [
            'type' => 'about:blank',
            'title' => self::title($code),
            'status' => $status,
            'code' => $code,
        ];

        if ($status >= 500 && config('app.debug')) {
            $body['detail'] = $e->getMessage();
        }

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        if (isset($extra['retry_after']) && is_int($extra['retry_after'])) {
            $headers['Retry-After'] = (string) $extra['retry_after'];
        }

        return new JsonResponse(array_merge($extra, $body), $status, $headers);
    }

    /**
     * @return array{0: int, 1: string, 2: array<string, mixed>}
     */
    private static function classify(Throwable $e): array
    {
        return match (true) {
            $e instanceof DomainException => [$e->status, $e->errorCode, $e->context],
            $e instanceof ValidationException => [422, 'validation.failed', ['errors' => $e->errors()]],
            $e instanceof AuthenticationException => [401, 'auth.unauthenticated', []],
            $e instanceof AuthorizationException => self::fromStatus($e->status() ?? 403),
            $e instanceof ModelNotFoundException => [404, 'resource.not_found', []],
            $e instanceof HttpExceptionInterface => self::fromStatus($e->getStatusCode()),
            default => [500, 'server.error', []],
        };
    }

    /**
     * @return array{0: int, 1: string, 2: array<string, mixed>}
     */
    private static function fromStatus(int $status): array
    {
        $code = self::HTTP_CODES[$status] ?? ($status >= 500 ? 'server.error' : 'http.error');

        return [$status, $code, []];
    }

    private static function title(string $code): string
    {
        $title = __('errors.'.$code);

        return is_string($title) && $title !== 'errors.'.$code ? $title : (string) __('errors.generic');
    }
}
