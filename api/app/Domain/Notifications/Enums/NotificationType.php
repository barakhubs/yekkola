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
    /** A payout refused before sending (identity check missing, account suspended…): the money waits. */
    case PayoutHeld = 'payout_held';
    case KycDecided = 'kyc_decided';
    case AccountStatus = 'account_status';
    case CourseTakenDown = 'course_taken_down';
    case Warning = 'warning';
    /** Staff alerts (PRD-10 FR-09): routed by permission, never turned off. */
    case StaffAlert = 'staff_alert';

    /** Users may turn these off per channel (FR-05). */
    public function canDisable(): bool
    {
        return in_array($this, [self::CourseUpdated, self::QuestionAnswered, self::CertificateIssued, self::NewQuestion, self::NewReview, self::NewSale], true);
    }

    /** Emailed once a day in the professor digest (FR-06) instead of one email each. */
    public function inDigest(): bool
    {
        return in_array($this, [self::NewQuestion, self::NewReview, self::NewSale], true);
    }

    /**
     * Channels a user can switch for an event they may turn off: its catalogue channels, never SMS.
     *
     * @return list<NotificationChannel>
     */
    public function settableChannels(): array
    {
        if (! $this->canDisable()) {
            return [];
        }

        return array_values(array_filter($this->channels(), fn (NotificationChannel $c) => $c !== NotificationChannel::Sms));
    }

    /**
     * @return list<NotificationChannel>
     */
    public function channels(): array
    {
        $in = NotificationChannel::InApp;
        $push = NotificationChannel::Push;
        $email = NotificationChannel::Email;
        $sms = NotificationChannel::Sms;

        return match ($this) {
            // A sale's SMS goes only to a payer without the app (Notifier::sms), never to account holders.
            self::PayoutSent, self::PayoutFailed, self::ApplicationDecided => [$in, $push, $email, $sms],
            self::AccountStatus => [$in, $email, $sms],
            self::PaymentSucceeded, self::PayoutHeld, self::RefundDecided, self::CertificateIssued, self::VersionDecided, self::KycDecided => [$in, $push, $email],
            self::CourseTakenDown, self::Warning, self::StaffAlert => [$in, $email],
            self::PaymentFailed, self::CourseUpdated, self::QuestionAnswered => [$in, $push],
            // Email for these comes as the daily digest (FR-06), not one each.
            self::NewQuestion, self::NewReview, self::NewSale => [$in, $push, $email],
        };
    }
}
