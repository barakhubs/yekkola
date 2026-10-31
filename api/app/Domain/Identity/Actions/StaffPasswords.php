<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;

/**
 * Email + password sign-in for staff (owner decision, 2026-10-31): an extra way into the back office. Students and
 * professors keep phone + SMS code only. Passwords are hashed; every failure answers the same way, so it never
 * tells whether an email exists or belongs to staff.
 */
final class StaffPasswords
{
    private static ?string $dummy = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Hasher $hasher,
        private readonly RecordAudit $recordAudit,
    ) {}

    /** The staff member with this email and password, or invalid credentials. */
    public function signIn(string $email, string $password): User
    {
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
        $hash = (string) $user?->password;

        // Always check one hash, so a missing account takes as long as a wrong password.
        $valid = $this->hasher->check($password, $hash !== '' ? $hash : $this->dummyHash());
        if ($user === null || ! $valid || ! ChangeUserStatus::isStaff($user) || $user->isBanned()) {
            throw IdentityException::invalidCredentials();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    /** A hash made once per process, checked when there's no account (same cost as a real check). */
    private function dummyHash(): string
    {
        return self::$dummy ??= $this->hasher->make('no-account');
    }

    /**
     * A staff member sets their own password: with the current one if they have one, otherwise after a recent sign-in
     * (by SMS code). Other sessions are signed out.
     */
    public function change(User $user, string $new, ?string $current, ?CarbonInterface $signedInAt): User
    {
        if (! ChangeUserStatus::isStaff($user)) {
            throw IdentityException::invalidCredentials();
        }
        $hash = (string) $user->password;
        if ($hash !== '') {
            if ($current === null || ! $this->hasher->check($current, $hash)) {
                throw IdentityException::currentPasswordWrong();
            }
        } else {
            ChangePhone::assertRecentSignIn($signedInAt);
        }

        return $this->db->transaction(function () use ($user, $new): User {
            $user->forceFill([
                'password' => $this->hasher->make($new),
                'password_changed_at' => now(),
                'must_change_password' => false,
            ])->save();
            $user->increment('auth_epoch'); // other sessions end; the caller keeps the current one
            $this->recordAudit->handle('staff.password_changed', $user, causer: $user);

            return $user->refresh();
        });
    }

    /**
     * A staff manager sets someone's password (no email reset yet): they must choose their own at next sign-in, and
     * every session is signed out.
     */
    public function setFor(User $user, string $password, User $by, SignOutEverywhere $signOut): User
    {
        ChangeUserStatus::assertMayManage($user, $by);
        if (! ChangeUserStatus::isStaff($user)) {
            throw IdentityException::notAStaffRole();
        }

        return $this->db->transaction(function () use ($user, $password, $by, $signOut): User {
            $user->forceFill([
                'password' => $this->hasher->make($password),
                'password_changed_at' => now(),
                'must_change_password' => true,
            ])->save();
            $signOut->handle($user);
            $this->recordAudit->handle('staff.password_set', $user, causer: $by);

            return $user->refresh();
        });
    }
}
