<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\ChangeUserStatus;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Support\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin impersonation (owner request, 2026-10-31): view the platform as another account for up to an hour,
 * then back.
 */
final class ImpersonationController extends Controller
{
    /**
     * Super admins only, browser sessions only; never another super admin or a banned account. `home` says where to
     * go next: the back office for staff, the web app for everyone else.
     */
    public function start(Request $request, User $user, Impersonation $impersonation): JsonResponse
    {
        /** @var User $by */
        $by = $request->user();
        $impersonation->start($request, $user, $by);

        return new JsonResponse(['data' => [
            'user' => new UserResource($user->load('province')),
            /** @var string `admin` or `web` */
            'home' => ChangeUserStatus::isStaff($user) ? 'admin' : 'web',
            /** Where to open the session: that app's home. */
            'redirect_to' => (string) config(ChangeUserStatus::isStaff($user) ? 'yekkola.admin_url' : 'yekkola.web_url'),
        ]]);
    }

    /**
     * Back to one's own account. 409 `auth.impersonation_ended` when no impersonation is running.
     */
    public function stop(Request $request, Impersonation $impersonation): JsonResponse
    {
        $by = Impersonation::current($request) === null ? null : $impersonation->stop($request);
        if ($by === null) {
            throw IdentityException::impersonationEnded();
        }

        return new JsonResponse(['data' => [
            'user' => new UserResource($by->load('province')),
            /** Back to the accounts list in the back office. */
            'redirect_to' => rtrim((string) config('yekkola.admin_url'), '/').'/users',
        ]]);
    }
}
