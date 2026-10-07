<?php

declare(strict_types=1);

use App\Http\ApiErrorResponse;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api_v1.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cookie (SPA) auth for the web app and back office; bearer tokens for mobile.
        $middleware->statefulApi();
        // Global so every response — including 404s for unknown routes — is localised.
        $middleware->prepend(SetLocaleFromHeader::class);
        $middleware->alias(['idempotent' => EnsureIdempotency::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiErrorResponse::render($e, $request);
            }

            return null;
        });
    })->create();
