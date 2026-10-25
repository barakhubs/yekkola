<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\CourseAccess;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Learning\Models\QuizAttempt;
use Illuminate\Database\ConnectionInterface;

/**
 * Grades a quiz attempt against the quiz version it started on (PRD-06 FR-07, §7): a question scores its
 * points when the chosen options are exactly the correct ones. Answers arriving after the time limit plus
 * a grace period don't count (the attempt closes with a score of 0). A passed quiz completes its lesson
 * (progress is keyed by lesson uid, so it counts in any later version too). Access is checked again at
 * submission (a refund or ban since starting ends it). Submitting again returns the stored result, so a
 * retry after a lost response is safe.
 */
final class SubmitQuizAttempt
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SyncProgress $progress,
        private readonly RecordCourseProgress $courseProgress,
        private readonly CourseAccess $access,
    ) {}

    /**
     * @param  array<string, list<string>>  $answers  question id => chosen option ids
     */
    public function handle(User $user, QuizAttempt $attempt, array $answers): QuizAttempt
    {
        return $this->db->transaction(function () use ($user, $attempt, $answers): QuizAttempt {
            $attempt = QuizAttempt::query()->where('user_id', $user->id)->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($attempt->submitted_at !== null) {
                return $attempt; // already graded: the stored result, whatever is sent again
            }
            $this->access->lesson($user, $attempt->lesson_uid, allowPreview: true);

            $timedOut = $attempt->deadline_at !== null && $attempt->deadline_at->isPast();
            $answers = $timedOut ? [] : $this->normalise($answers, $attempt);
            $quiz = $attempt->quiz()->with('questions.options')->firstOrFail();

            $total = 0;
            $earned = 0;
            foreach ($quiz->questions as $question) {
                $total += $question->points;
                if ($question->isAnsweredBy($answers[$question->id] ?? [])) {
                    $earned += $question->points;
                }
            }

            $score = $total === 0 ? 0 : intdiv($earned * 100, $total);
            $attempt->forceFill([
                'answers' => $answers,
                'submitted_at' => now(),
                'score_pct' => $score,
                'passed' => $score >= $quiz->pass_mark_pct,
                'timed_out' => $timedOut,
            ])->save();

            if ($attempt->passed) {
                $this->completeLesson($user, $attempt);
            }

            return $attempt;
        });
    }

    private function completeLesson(User $user, QuizAttempt $attempt): void
    {
        $lesson = Lesson::query()->whereHas('quiz', fn ($q) => $q->whereKey($attempt->quiz_id))->firstOrFail();
        $this->progress->complete($user, $lesson, $attempt->course_id);

        $enrollment = Enrollment::query()->active()->where('user_id', $user->id)->where('course_id', $attempt->course_id)->lockForUpdate()->first();
        if ($enrollment !== null) { // preview quizzes have none
            $this->courseProgress->handle($enrollment, $attempt->lesson_uid);
        }
    }

    /**
     * Only questions of this attempt, only option ids as strings.
     *
     * @param  array<string, list<string>>  $answers
     * @return array<string, list<string>>
     */
    private function normalise(array $answers, QuizAttempt $attempt): array
    {
        $out = [];
        foreach ($attempt->question_order as $questionId) {
            $chosen = array_values(array_unique(array_map('strval', (array) ($answers[$questionId] ?? []))));
            sort($chosen);
            $out[$questionId] = $chosen;
        }

        return $out;
    }
}
