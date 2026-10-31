<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\SignOutEverywhere;
use App\Domain\Identity\Actions\StaffPasswords;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Resources\Admin\StaffMemberResource;
use App\Http\Resources\UserResource;
use App\Http\Support\AuthContext;
use App\Integrations\BotChallenge\BotChallenge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * Staff email + password (owner decision, 2026-10-31): sign in to the back office, change one's own password, or
 * have a staff manager set it. Browser sessions only; the apps keep SMS codes.
 */
final class StaffPasswordController extends Controller
{
    /**
     * Signs a staff member in with email + password (session cookie). Same answer for every failure.
     */
    public function signIn(Request $request, StaffPasswords $passwords, BotChallenge $bots): UserResource
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'bot_token' => ['nullable', 'string', 'max:4096'],
        ]);
        if (! AuthContext::client($request)->isBrowser) {
            throw IdentityException::clientUnknown();
        }
        if (! $bots->verify((string) ($data['bot_token'] ?? ''), (string) $request->ip())) {
            throw IdentityException::botChallengeFailed();
        }

        $user = $passwords->signIn((string) $data['email'], (string) $data['password']);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(EnsureAccountActive::SESSION_EPOCH_KEY, $user->auth_epoch);
        $request->session()->put(AuthContext::SESSION_SIGNED_IN_AT, now()->getTimestamp());

        return new UserResource($user->load('province'));
    }

    /**
     * Sets one's own password (staff): the current one is required when there is one, otherwise a recent sign-in.
     * Other sessions are signed out; this one stays.
     */
    public function change(Request $request, StaffPasswords $passwords): UserResource
    {
        $data = $request->validate([
            'current_password' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'max:255', Password::min(12)->letters()->numbers()],
        ]);
        /** @var User $user */
        $user = $request->user();

        $user = $passwords->change($user, (string) $data['password'], $data['current_password'] ?? null, AuthContext::signedInAt($request));

        if ($request->hasSession()) {
            $request->session()->put(EnsureAccountActive::SESSION_EPOCH_KEY, $user->auth_epoch);
        }

        return new UserResource($user->load('province'));
    }

    /**
     * A staff manager sets a staff member's password; they choose their own at next sign-in.
     */
    public function setFor(Request $request, User $user, StaffPasswords $passwords, SignOutEverywhere $signOut): StaffMemberResource
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255', Password::min(12)->letters()->numbers()],
        ]);
        /** @var User $by */
        $by = $request->user();

        return new StaffMemberResource($passwords->setFor($user, (string) $data['password'], $by, $signOut)->load('roles', 'permissions'));
    }
}
