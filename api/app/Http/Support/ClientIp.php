<?php

declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Http\Request;

/**
 * Who a request is really from, for rate limits. Public pages are rendered by our web server, so without this
 * every visitor would share the web server's IP and limit. The web server proves itself with a shared key
 * (`X-Web-Server-Key`) and passes the visitor's IP in `X-Client-Ip`; anyone else is keyed by their own IP.
 */
final class ClientIp
{
    public const SERVER_KEY_HEADER = 'X-Web-Server-Key';

    public const CLIENT_IP_HEADER = 'X-Client-Ip';

    /** True for requests from our own web server (rendering public pages). */
    public static function fromWebServer(Request $request): bool
    {
        $expected = (string) config('yekkola.web_server_key');
        $given = $request->header(self::SERVER_KEY_HEADER);

        return $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }

    /**
     * The visitor's IP: forwarded by our web server, or the connection's own. Null when our web server renders
     * a cached page with no visitor behind it (a revalidation).
     */
    public static function of(Request $request): ?string
    {
        if (! self::fromWebServer($request)) {
            return (string) $request->ip();
        }

        $forwarded = $request->header(self::CLIENT_IP_HEADER);

        return is_string($forwarded) && filter_var($forwarded, FILTER_VALIDATE_IP) !== false ? $forwarded : null;
    }
}
