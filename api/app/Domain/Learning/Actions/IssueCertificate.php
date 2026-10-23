<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Events\CertificateIssued;
use App\Domain\Learning\Models\Certificate;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Learning\Models\QuizAttempt;
use App\Domain\Platform\Settings\LearningSettings;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Issues a certificate once the admin's completion rule is met (PRD-06 FR-10): enough of the live
 * version completed and, if the rule says so, every quiz passed. Idempotent: one valid certificate per
 * student and course. Waits for a name — a certificate without one proves nothing.
 */
final class IssueCertificate
{
    public function __construct(private readonly LearningSettings $settings, private readonly Dispatcher $events) {}

    public function handle(string $enrollmentId): ?Certificate
    {
        return DB::transaction(function () use ($enrollmentId): ?Certificate {
            $enrollment = Enrollment::query()->lockForUpdate()->find($enrollmentId);
            if ($enrollment === null || ! $enrollment->isActive()) {
                return null;
            }

            $existing = Certificate::query()->valid()->where('user_id', $enrollment->user_id)->where('course_id', $enrollment->course_id)->first();
            $user = User::query()->find($enrollment->user_id);
            $course = Course::query()->with('liveVersion', 'professor')->find($enrollment->course_id);
            if ($existing !== null || blank($user?->name) || $course?->liveVersion === null || ! $this->ruleMet($enrollment, $course)) {
                return $existing;
            }

            $certificate = Certificate::query()->create([
                'serial' => Certificate::newSerial(),
                'user_id' => $enrollment->user_id,
                'course_id' => $course->id,
                'enrollment_id' => $enrollment->id,
                'course_version_id' => $course->live_version_id,
                'student_name_snapshot' => $user->name,
                'course_title_snapshot' => $course->liveVersion->title,
                'professor_name_snapshot' => $course->professor->display_name,
                'issued_at' => now(),
            ]);
            $this->events->dispatch(new CertificateIssued($certificate->id));

            return $certificate;
        });
    }

    private function ruleMet(Enrollment $enrollment, Course $course): bool
    {
        if ($enrollment->progress_pct < $this->settings->certificate_min_completion_pct) {
            return false;
        }
        if (! $this->settings->certificate_requires_quizzes_passed) {
            return true;
        }

        $quizUids = Lesson::query()->where('course_version_id', $course->live_version_id)->where('type', LessonType::Quiz)->pluck('uid');
        $passed = QuizAttempt::query()->where('user_id', $enrollment->user_id)->whereIn('lesson_uid', $quizUids)
            ->where('passed', true)->distinct()->count('lesson_uid');

        return $passed === $quizUids->count();
    }
}
