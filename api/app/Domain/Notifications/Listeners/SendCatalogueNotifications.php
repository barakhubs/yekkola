<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Authoring\Events\CourseVersionDecided;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\CourseVersion;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Events\OrderPaid;
use App\Domain\Commerce\Events\RefundDecided;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Finance\Enums\PayoutStatus;
use App\Domain\Finance\Models\Payout;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Events\CertificateIssued;
use App\Domain\Learning\Models\Certificate;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Learning\Models\Review;
use App\Domain\Learning\Models\ThreadReply;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Jobs\NotifyCourseUpdated;
use App\Domain\Notifications\Notifier;
use App\Domain\Professors\Enums\ApplicationStatus;
use App\Domain\Professors\Enums\KycSubmissionStatus;
use App\Domain\Professors\Events\ApplicationDecided;
use App\Domain\Professors\Models\KycSubmission;
use App\Domain\Professors\Models\ProfessorProfile;

/**
 * Turns platform events into catalogue notifications (PRD-10 §3). Each handler only names the recipient, the
 * event and a deep link; notifications are queued after the transaction commits.
 */
final class SendCatalogueNotifications
{
    public function __construct(private readonly Notifier $notify) {}

    public function orderPaid(OrderPaid $event): void
    {
        $order = Order::query()->with('buyer', 'beneficiary', 'items.course.liveVersion', 'items.course.professor.user')->find($event->orderId);
        if ($order === null || $order->total_minor === 0) {
            return; // free enrolments aren't sales or payments
        }

        $buyer = $order->buyer;
        $locale = $buyer->locale ?? 'fr';
        $amount = Notifier::money($order->total_minor, $order->currency, $locale);
        $this->notify->user($buyer, NotificationType::PaymentSucceeded, 'payment_succeeded.receipt', ['amount' => $amount, 'number' => $order->number], '/orders');
        if ($order->beneficiary_id !== $order->buyer_id) {
            $this->notify->user($order->beneficiary, NotificationType::PaymentSucceeded, 'payment_succeeded.gift', ['number' => $order->number], '/my-courses');
        }
        // A parent paying from their own number gets the confirmation by SMS (no app).
        $payer = $order->payer();
        if ($payer !== null && $payer->e164 !== $buyer?->phone_e164) {
            $this->notify->sms($payer->e164, NotificationType::PaymentSucceeded, 'payment_succeeded.payer', ['amount' => $amount, 'number' => $order->number], $locale);
        }

        foreach ($order->items as $item) {
            /** @var OrderItem $item */
            $professor = $item->course?->professor?->user;
            $this->notify->user($professor, NotificationType::NewSale, 'new_sale', ['course' => (string) $item->course?->liveVersion?->title], '/studio/courses');
        }
    }

    public function paymentSaved(Payment $payment): void
    {
        if ($payment->wasChanged('status') && $payment->status === PaymentStatus::Failed) {
            $order = $payment->order()->with('buyer')->first();
            $this->notify->user($order?->buyer, NotificationType::PaymentFailed, 'payment_failed.failed', ['number' => (string) $order?->number], '/orders');
        }
    }

    public function orderSaved(Order $order): void
    {
        if ($order->wasChanged('status') && $order->status === OrderStatus::Expired) {
            $this->notify->user($order->buyer, NotificationType::PaymentFailed, 'payment_failed.expired', ['number' => $order->number], '/orders');
        }
    }

    public function refundDecided(RefundDecided $event): void
    {
        $refund = Refund::query()->with('order.buyer')->find($event->refundId);
        if ($refund === null || ! in_array($refund->status, [RefundStatus::Approved, RefundStatus::Rejected], true)) {
            return;
        }
        $buyer = $refund->order?->buyer;
        $variant = $refund->status === RefundStatus::Approved ? 'approved' : 'rejected';
        $this->notify->user($buyer, NotificationType::RefundDecided, "refund_decided.{$variant}", [
            'amount' => Notifier::money($refund->amount_minor, $refund->currency, $buyer->locale ?? 'fr'),
            'number' => (string) $refund->order?->number,
        ], '/account');
    }

    public function versionDecided(CourseVersionDecided $event): void
    {
        $version = $event->version->loadMissing('course.professor.user');
        $course = $version->course;
        if ($course === null || ! in_array($version->state, [VersionState::Approved, VersionState::Rejected], true)) {
            return;
        }

        $approved = $version->state === VersionState::Approved;
        $this->notify->user($course->professor?->user, NotificationType::VersionDecided, 'version_decided.'.($approved ? 'approved' : 'rejected'), ['course' => (string) $version->title], "/studio/courses/{$course->id}");

        // A new version of a course students already follow (not its first publication).
        $isUpdate = CourseVersion::query()->where('course_id', $course->id)->where('state', VersionState::Superseded)->exists();
        if ($approved && $isUpdate) {
            NotifyCourseUpdated::dispatch($course->id)->afterCommit();
        }
    }

    public function certificateIssued(CertificateIssued $event): void
    {
        $certificate = Certificate::query()->with('user', 'course.liveVersion')->find($event->certificateId);
        $this->notify->user($certificate?->user, NotificationType::CertificateIssued, 'certificate_issued', ['course' => (string) $certificate?->course?->liveVersion?->title], '/account');
    }

    public function applicationDecided(ApplicationDecided $event): void
    {
        $application = $event->application->loadMissing('user');
        $variant = match ($application->status) {
            ApplicationStatus::Approved => 'approved',
            ApplicationStatus::Rejected => 'rejected',
            ApplicationStatus::NeedsInfo => 'needs_info',
            default => null,
        };
        if ($variant !== null) {
            $this->notify->user($application->user, NotificationType::ApplicationDecided, "application_decided.{$variant}", [], $variant === 'approved' ? '/studio' : '/studio/apply');
        }
    }

    public function payoutSaved(Payout $payout): void
    {
        if (! $payout->wasChanged('status') || ! in_array($payout->status, [PayoutStatus::Paid, PayoutStatus::Failed], true)) {
            return;
        }
        $user = $payout->professor?->user;
        $paid = $payout->status === PayoutStatus::Paid;
        $this->notify->user($user, $paid ? NotificationType::PayoutSent : NotificationType::PayoutFailed, $paid ? 'payout_sent' : 'payout_failed', [
            'amount' => Notifier::money($payout->amount_minor, $payout->currency, $user->locale ?? 'fr'),
            'last_digits' => mb_substr($payout->msisdn, -4), // never the full number (BR-03)
        ], '/studio/payouts');
    }

    public function kycSaved(KycSubmission $submission): void
    {
        if ($submission->wasChanged('status') && in_array($submission->status, [KycSubmissionStatus::Verified, KycSubmissionStatus::Rejected], true)) {
            $user = ProfessorProfile::query()->with('user')->find($submission->professor_profile_id)?->user;
            $this->notify->user($user, NotificationType::KycDecided, 'kyc_decided.'.$submission->status->value, [], '/studio/verification');
        }
    }

    public function userSaved(User $user): void
    {
        if ($user->wasChanged('status')) {
            $this->notify->user($user, NotificationType::AccountStatus, 'account_status.'.$user->status->value);
        }
    }

    public function courseSaved(Course $course): void
    {
        if ($course->wasChanged('taken_down_at') && $course->taken_down_at !== null) {
            $course->loadMissing('professor.user', 'liveVersion');
            $this->notify->user($course->professor?->user, NotificationType::CourseTakenDown, 'course_taken_down', ['course' => (string) $course->liveVersion?->title, 'reason' => (string) $course->takedown_reason], "/studio/courses/{$course->id}");
        }
    }

    public function threadCreated(LessonThread $thread): void
    {
        $course = $thread->course()->with('professor.user')->first();
        $this->notify->user($course?->professor?->user, NotificationType::NewQuestion, 'new_question', ['title' => $thread->title], '/studio/questions');
    }

    public function replyCreated(ThreadReply $reply): void
    {
        $thread = $reply->thread()->with('author')->first();
        if ($reply->is_professor && $thread !== null && $thread->author_id !== $reply->author_id) {
            $this->notify->user($thread->author, NotificationType::QuestionAnswered, 'question_answered', ['title' => $thread->title], "/learn/{$thread->course_id}/{$thread->lesson_uid}");
        }
    }

    public function reviewCreated(Review $review): void
    {
        $course = $review->course()->with('professor.user', 'liveVersion')->first();
        $this->notify->user($course?->professor?->user, NotificationType::NewReview, 'new_review', ['course' => (string) $course?->liveVersion?->title, 'rating' => (string) $review->rating], '/studio/courses');
    }

    /** A staff warning after a report (PRD-09 FR-10). */
    public function warned(User $author, string $note): void
    {
        $this->notify->user($author, NotificationType::Warning, 'warning', ['note' => $note]);
    }
}
