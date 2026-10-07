<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Roles (spatie/laravel-permission, guard `web`). Every user is a student; the others are granted.
 */
enum Role: string
{
    case Student = 'student';
    case Professor = 'professor';
    case Moderator = 'moderator';
    case Admin = 'admin';
    case SuperAdmin = 'super-admin';

    /**
     * Permissions held by this role. Finance permissions are granted to staff individually
     * (PRD-09: "Finance (permission set, combinable)"), so no role gets them by default except super admin.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Student, self::Professor => [],
            self::Moderator => [
                Permission::ModerationReview,
                Permission::ModerationReports,
                Permission::UsersView,
                Permission::CatalogView,
            ],
            self::Admin => [
                Permission::ModerationReview,
                Permission::ModerationReports,
                Permission::UsersView,
                Permission::UsersManage,
                Permission::ProfessorsManage,
                Permission::CatalogView,
                Permission::CatalogManage,
                Permission::CommerceView,
                Permission::SettingsManage,
                Permission::StaffManage,
                Permission::KycView,
                Permission::AuditView,
            ],
            self::SuperAdmin => Permission::cases(),
        };
    }

    public function isStaff(): bool
    {
        return in_array($this, [self::Moderator, self::Admin, self::SuperAdmin], true);
    }
}
