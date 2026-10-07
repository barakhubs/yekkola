<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders any exception on API routes as the standard error envelope (docs/architecture.md §4.1):
 *
 *   { "type", "title", "status", "code", "detail"?, "errors"?, ...context }
 *
 * `code` is stable and machine-readable; `title` is translated (lang/{locale}/errors.php).
 */
final class ApiErrorResponse
{
    public static function render(Throwable $e, Request $request): JsonResponse
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

        $headers = [];
        if ($e instanceof HttpExceptionInterface) {
            $headers = $e->getHeaders();
        }

        return new JsonResponse(array_merge($body, $extra), $status, $headers);
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
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [403, 'auth.forbidden', []],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'resource.not_found', []],
            $e instanceof MethodNotAllowedHttpException => [405, 'http.method_not_allowed', []],
            $e instanceof ThrottleRequestsException => [429, 'rate_limited', []],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'http.error', []],
            default => [500, 'server.error', []],
        };
    }

    private static function title(string $code): string
    {
        $key = 'errors.'.$code;
        $title = __($key);

        return $title === $key ? __('errors.generic') : $title;
    }
}
