<?php

declare(strict_types=1);

use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

uses(Tests\TestCase::class);

it('uses French when no Accept-Language header is sent', function () {
    $request = Request::create('/api/v1/ping');
    $request->headers->remove('Accept-Language');

    $response = (new SetLocaleFromHeader)->handle($request, fn () => new Response('ok'));

    expect(App::getLocale())->toBe('fr')
        ->and($response->headers->get('Content-Language'))->toBe('fr');
});
