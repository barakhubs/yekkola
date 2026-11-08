<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

/**
 * The notification catalogue (PRD-10 §3): which channels each event uses and whether users may turn it off.
 * Critical events ignore preferences (BR-02); SMS only where the catalogue marks it (BR-01). Push joins with the
 * FCM channel.
 */
enum NotificationType: string
{
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentFailed = 'payment_failed';
    case RefundDecided = 'refund_decided';
    case CourseUpdated = 'course_updated';
    case QuestionAnswered = 'question_answered';
    case CertificateIssued = 'certificate_issued';
    case ApplicationDecided = 'application_decided';
    case VersionDecided = 'version_decided';
    case NewQuestion = 'new_question';
    case NewReview = 'new_review';
    case NewSale = 'new_sale';
    case PayoutSent = 'payout_sent';
    case PayoutFailed = 'payout_failed';
    case KycDecided = 'kyc_decided';
    case AccountStatus = 'account_status';
    case CourseTakenDown = 'course_taken_down';
    case Warning = 'warning';

    /** Users may turn these off per channel (FR-05). */
    public function canDisable(): bool
    {
        return in_array($this, [self::CourseUpdated, self::QuestionAnswered, self::CertificateIssued, self::NewQuestion, self::NewReview, self::NewSale], true);
    }

    /**
     * @return list<NotificationChannel>
     */
    public function channels(): array
    {
        return match ($this) {
            self::PaymentSucceeded, self::PayoutSent, self::PayoutFailed, self::ApplicationDecided, self::AccountStatus => [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Sms],
            self::RefundDecided, self::CertificateIssued, self::VersionDecided, self::KycDecided, self::CourseTakenDown, self::Warning => [NotificationChannel::InApp, NotificationChannel::Email],
            // Professors' sales, reviews and questions: email comes as the daily digest (FR-06), not one each.
            self::PaymentFailed, self::CourseUpdated, self::QuestionAnswered, self::NewQuestion, self::NewReview, self::NewSale => [NotificationChannel::InApp],
        };
    }
}
