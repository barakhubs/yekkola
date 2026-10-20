<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Models\Quiz;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\CourseAccess;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Learning\Models\QuizAttempt;
use Illuminate\Database\ConnectionInterface;

/**
 * Starts (or resumes) a quiz attempt (PRD-06 FR-07): enrolled students, or anyone signed in on a preview
 * quiz. An open attempt is resumed rather than duplicated; the attempt limit counts every started attempt.
 * Questions are shuffled once if the quiz says so; the deadline is the time limit plus a grace period.
 * The answer key is never part of the response (QuizAttemptResource).
 */
final class StartQuizAttempt
{
    /** Network slack added to the time limit. */
    public const GRACE_SECONDS = 30;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CourseAccess $access,
    ) {}

    public function handle(User $user, string $lessonUid, ?string $deviceId = null): QuizAttempt
    {
        [$lesson, $course] = $this->access->lesson($user, $lessonUid, allowPreview: true);
        if ($lesson->type !== LessonType::Quiz) {
            throw LearningException::notAQuiz();
        }
        $quiz = Quiz::query()->where('lesson_id', $lesson->id)->with('questions')->firstOrFail();

        return $this->db->transaction(function () use ($user, $quiz, $course, $lessonUid, $deviceId): QuizAttempt {
            // Serialise starts for this student and quiz (two taps, two devices).
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->first();

            $attempts = QuizAttempt::query()->where('user_id', $user->id)->where('lesson_uid', $lessonUid)->get();
            // Resume an open attempt, even one started before a new version went live (graded on its own version).
            $open = $attempts->first(fn (QuizAttempt $a) => $a->isOpen());
            if ($open !== null) {
                return $open;
            }

            if ($quiz->max_attempts !== null && $attempts->count() >= $quiz->max_attempts) {
                throw LearningException::maxAttemptsReached($quiz->max_attempts);
            }

            $order = $quiz->questions->pluck('id')->all();
            if ($quiz->shuffle_questions) {
                shuffle($order);
            }

            return QuizAttempt::query()->create([
                'user_id' => $user->id,
                'quiz_id' => $quiz->id,
                'course_id' => $course->id,
                'lesson_uid' => $lessonUid,
                'question_order' => $order,
                'started_at' => now(),
                'deadline_at' => $quiz->time_limit_seconds === null ? null : now()->addSeconds($quiz->time_limit_seconds + self::GRACE_SECONDS),
                'device_id' => $deviceId,
            ]);
        });
    }
}
