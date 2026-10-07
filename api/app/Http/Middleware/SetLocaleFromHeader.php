<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the response language from `Accept-Language` (fr or en). French is the default.
 */
final class SetLocaleFromHeader
{
    private const SUPPORTED = ['fr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        // No header → platform default (French). Unsupported languages fall back to the first supported (French).
        $locale = $request->headers->has('Accept-Language')
            ? ($request->getPreferredLanguage(self::SUPPORTED) ?? config('app.locale'))
            : config('app.locale');

        App::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
