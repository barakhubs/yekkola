<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Jobs\RefreshCourseRatings;
use App\Domain\Learning\Models\Review;
use App\Domain\Platform\Actions\RecordAudit;
use Illuminate\Database\ConnectionInterface;

/**
 * Suspends, bans or reactivates an account (PRD-09 FR-04, PRD-01 FR-15), with a reason in the audit log.
 * Suspending or banning signs the account out everywhere; a suspended account may sign in to read why, a banned
 * one may not. A ban hides the account's reviews, so the ratings of the courses they reviewed are recounted.
 * Nobody changes their own status, and only staff managers change another staff member's.
 */
final class ChangeUserStatus
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SignOutEverywhere $signOut,
        private readonly RecordAudit $recordAudit,
    ) {}

    public function handle(User $user, UserStatus $status, string $note, User $staff): User
    {
        self::assertMayManage($user, $staff);

        $before = $user->status;
        if ($before === $status) {
            return $user;
        }

        $this->db->transaction(function () use ($user, $status, $note, $staff, $before): void {
            $user->forceFill(['status' => $status])->save();
            if ($status !== UserStatus::Active) {
                $this->signOut->handle($user);
            }
            $this->recordAudit->handle('user.status_changed', $user, ['from' => $before->value, 'to' => $status->value, 'note' => $note], causer: $staff);
        });

        if ($before === UserStatus::Banned || $status === UserStatus::Banned) {
            foreach (Review::query()->where('user_id', $user->id)->distinct()->pluck('course_id') as $courseId) {
                RefreshCourseRatings::dispatch((string) $courseId);
            }
        }

        return $user->refresh();
    }

    /** Nobody acts on their own account here; only staff managers act on another staff member's. */
    public static function assertMayManage(User $user, User $staff): void
    {
        if ($user->id === $staff->id) {
            throw IdentityException::ownAccountStatus();
        }
        if (self::isStaff($user) && ! $staff->can(Permission::StaffManage->value)) {
            throw IdentityException::staffAccountProtected();
        }
    }

    public static function isStaff(User $user): bool
    {
        return $user->hasAnyRole(array_values(array_map(
            fn (Role $role): string => $role->value,
            array_filter(Role::cases(), fn (Role $role): bool => $role->isStaff()),
        )));
    }
}
