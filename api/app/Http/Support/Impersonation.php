<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Identity\Actions\ChangeUserStatus;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Http\Middleware\EnsureAccountActive;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Super-admin impersonation (owner request, 2026-10-31): a super admin's browser session becomes another account's
 * for at most an hour, to see exactly what they see, in the web app and the back office alike (one API session).
 * Never another super admin, never a banned account; sessions only (no app tokens). Start and stop are audited, and
 * every audit entry made meanwhile names the super admin (RecordAudit). Money, security and account changes are
 * refused while impersonating (EnsureImpersonationLimits).
 */
final class Impersonation
{
    public const SESSION_KEY = 'impersonation';

    public const MINUTES = 60;

    /**
     * Route names refused while impersonating (`*` matches anything): paying, refunds, payouts, ledger, staff and
     * settings changes, and anything that changes the impersonated account itself.
     */
    public const FORBIDDEN_ROUTES = [
        'orders.store', 'orders.pay', 'orders.refund-requests.store', 'courses.enroll',
        'me.update', 'me.destroy', 'me.deletion.*', 'me.phone.*', 'me.password', 'me.export.store', 'me.devices.destroy',
        'professor.payout-method.*', 'professor.kyc.store', 'professor.terms.accept',
        'admin.refunds.approve', 'admin.refunds.reject', 'admin.refunds.retry', 'admin.payments.*',
        'admin.payout-batches.store', 'admin.payout-batches.approve', 'admin.payouts.*', 'admin.ledger.adjustments.*',
        'admin.staff.store', 'admin.staff.update', 'admin.staff.destroy', 'admin.staff.password',
        'admin.users.status', 'admin.users.sign-out', 'admin.users.devices.*', 'admin.users.impersonate',
        'admin.settings.update',
    ];

    public function __construct(private readonly RecordAudit $recordAudit) {}

    /** Whether this super admin may view the platform as this account. */
    public static function allowed(User $by, User $target): bool
    {
        return $by->hasRole(Role::SuperAdmin->value)
            && $by->id !== $target->id
            && ! $target->hasRole(Role::SuperAdmin->value)
            && ! $target->isBanned();
    }

    public function start(Request $request, User $target, User $by): void
    {
        if (self::current($request) !== null || ! self::allowed($by, $target)) {
            throw IdentityException::impersonationNotAllowed();
        }
        if (! $request->hasSession() || AuthContext::currentToken($request) !== null) {
            throw IdentityException::clientUnknown(); // browser sessions only
        }

        $this->recordAudit->handle('impersonation.started', $target, ['as_staff' => ChangeUserStatus::isStaff($target)], causer: $by);

        Auth::guard('web')->login($target);
        $request->session()->regenerate();
        $request->session()->put(EnsureAccountActive::SESSION_EPOCH_KEY, $target->auth_epoch);
        $request->session()->put(self::SESSION_KEY, [
            'by' => $by->id,
            'started_at' => now()->getTimestamp(),
            'expires_at' => now()->addMinutes(self::MINUTES)->getTimestamp(),
        ]);
    }

    /** Back to the super admin's own account. Returns them, or null when they can no longer be signed in. */
    public function stop(Request $request, string $reason = 'stopped'): ?User
    {
        $current = self::current($request);
        if ($current === null) {
            return null;
        }
        $by = User::query()->find($current['by']);
        $target = $request->user();
        $request->session()->forget(self::SESSION_KEY);

        if ($by === null || ! $by->hasRole(Role::SuperAdmin->value) || $by->isBanned()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return null;
        }

        $this->recordAudit->handle('impersonation.ended', $target instanceof User ? $target : null, ['reason' => $reason], causer: $by);
        Auth::guard('web')->login($by);
        $request->session()->regenerate();
        $request->session()->put(EnsureAccountActive::SESSION_EPOCH_KEY, $by->auth_epoch);

        return $by;
    }

    /**
     * The impersonation running in this session, if any.
     *
     * @return array{by: string, started_at: int, expires_at: int}|null
     */
    public static function current(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }
        $value = $request->session()->get(self::SESSION_KEY);

        return is_array($value) && isset($value['by'], $value['started_at'], $value['expires_at'])
            ? ['by' => (string) $value['by'], 'started_at' => (int) $value['started_at'], 'expires_at' => (int) $value['expires_at']]
            : null;
    }

    public static function isForbidden(?string $routeName): bool
    {
        if ($routeName === null) {
            return false;
        }
        foreach (self::FORBIDDEN_ROUTES as $pattern) {
            if (fnmatch($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }
}
