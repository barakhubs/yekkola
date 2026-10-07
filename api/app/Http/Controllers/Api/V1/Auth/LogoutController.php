<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\SignOut;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class LogoutController extends Controller
{
    /**
     * Sign out of this session (web) or revoke this device's token (mobile). The device stays registered.
     */
    public function __invoke(Request $request, SignOut $signOut): Response
    {
        $signOut->handle($request->user()?->currentAccessToken(), $request->hasSession() ? $request->session() : null);

        return response()->noContent();
    }
}
