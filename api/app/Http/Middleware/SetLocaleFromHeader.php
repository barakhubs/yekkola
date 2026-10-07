<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the response language from `Accept-Language` (fr or en). French is the default and the fallback
 * for unsupported languages.
 */
final class SetLocaleFromHeader
{
    /** First entry is the default. */
    private const SUPPORTED = ['fr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        // Symfony returns the first supported locale when nothing in the header matches.
        $locale = $request->headers->has('Accept-Language')
            ? (string) $request->getPreferredLanguage(self::SUPPORTED)
            : self::SUPPORTED[0];

        App::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);
        $response->setVary('Accept-Language', false);

        return $response;
    }
}
