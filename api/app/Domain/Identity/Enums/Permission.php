<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Staff permissions (PRD-09 §3). Policies check these, never role names.
 */
enum Permission: string
{
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case ProfessorsManage = 'professors.manage';
    case ModerationReview = 'moderation.review';
    case ModerationReports = 'moderation.reports';
    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';
    case CommerceView = 'commerce.view';
    case FinanceView = 'finance.view';
    case FinanceRefunds = 'finance.refunds';
    case FinancePayouts = 'finance.payouts';
    case FinanceAdjustments = 'finance.adjustments';
    case SettingsManage = 'settings.manage';
    case StaffManage = 'staff.manage';
    /** Assign or change staff roles — super admin only (PRD-09 §3). */
    case StaffRoles = 'staff.roles';
    case AuditView = 'audit.view';
    case KycView = 'kyc.view';
}
