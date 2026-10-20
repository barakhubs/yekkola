<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\ProgressStatus;
use App\Domain\Learning\Models\Enrollment;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Batch progress sync from web and mobile (PRD-06 FR-04, FR-05). Each item is merged monotonically in one
 * atomic upsert: the furthest position wins and completion never regresses, whatever order devices sync
 * in. The server decides completion — video/audio at 90% watched, documents when marked done; quizzes only
 * by passing (SubmitQuizAttempt). Completion times are server time; device time only orders a batch.
 * Lessons not in the live version of an actively enrolled course are skipped.
 */
final class SyncProgress
{
    /** Share of a video/audio lesson that counts as watched. */
    public const WATCHED_PCT = 90;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RecordCourseProgress $courseProgress,
    ) {}

    /**
     * @param  list<array{lesson_uid: string, position_seconds?: int|null, completed?: bool|null, client_updated_at?: string|null}>  $items
     * @return array{accepted: list<string>, skipped: list<string>, courses: array<string, int>}
     */
    public function handle(User $user, array $items, ?string $deviceId = null): array
    {
        usort($items, fn (array $a, array $b) => strcmp((string) ($a['client_updated_at'] ?? ''), (string) ($b['client_updated_at'] ?? '')));

        $lessons = $this->liveLessons($user, array_column($items, 'lesson_uid'));
        $accepted = [];
        $skipped = [];
        $touched = [];

        foreach ($items as $item) {
            $lesson = $lessons[$item['lesson_uid']] ?? null;
            if ($lesson === null) {
                $skipped[] = $item['lesson_uid'];

                continue;
            }

            $this->merge($user, $lesson['lesson'], $lesson['course_id'], $item, $deviceId);
            $accepted[] = $item['lesson_uid'];
            $touched[$lesson['course_id']] = $item['lesson_uid']; // the latest lesson per course
        }

        $courses = [];
        foreach ($touched as $courseId => $lastUid) {
            $courses[$courseId] = $this->db->transaction(function () use ($user, $courseId, $lastUid): int {
                $enrollment = Enrollment::query()->active()->where('user_id', $user->id)->where('course_id', $courseId)->lockForUpdate()->firstOrFail();

                return $this->courseProgress->handle($enrollment, $lastUid)->progress_pct;
            });
        }

        return ['accepted' => $accepted, 'skipped' => array_values(array_unique($skipped)), 'courses' => $courses];
    }

    /** Marks a lesson complete (passed quiz); same monotonic merge. Call inside the caller's transaction. */
    public function complete(User $user, Lesson $lesson, string $courseId): void
    {
        $this->upsert($user, $lesson, $courseId, 0, true, null, null);
    }

    /**
     * @param  array{lesson_uid: string, position_seconds?: int|null, completed?: bool|null, client_updated_at?: string|null}  $item
     */
    private function merge(User $user, Lesson $lesson, string $courseId, array $item, ?string $deviceId): void
    {
        $position = max(0, (int) ($item['position_seconds'] ?? 0));
        $completed = match ($lesson->type) {
            LessonType::Video, LessonType::Audio => $lesson->duration_seconds !== null && $lesson->duration_seconds > 0
                && $position * 100 >= $lesson->duration_seconds * self::WATCHED_PCT,
            LessonType::Document => (bool) ($item['completed'] ?? false),
            LessonType::Quiz => false, // only a passed attempt completes a quiz
        };
        $clientTime = isset($item['client_updated_at']) ? Carbon::parse($item['client_updated_at']) : null;

        $this->upsert($user, $lesson, $courseId, $position, $completed, $clientTime, $deviceId);
    }

    /** One atomic, monotonic upsert: concurrent syncs from several devices can't move anything backwards. */
    private function upsert(User $user, Lesson $lesson, string $courseId, int $position, bool $completed, ?Carbon $clientTime, ?string $deviceId): void
    {
        $now = now();

        $this->db->statement(<<<'SQL'
            INSERT INTO lesson_progress (id, user_id, course_id, lesson_uid, status, position_seconds, completed_at, client_updated_at, device_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (user_id, lesson_uid) DO UPDATE SET
                position_seconds = GREATEST(lesson_progress.position_seconds, EXCLUDED.position_seconds),
                status = CASE WHEN lesson_progress.status = 'completed' OR EXCLUDED.status = 'completed' THEN 'completed' ELSE 'in_progress' END,
                completed_at = COALESCE(lesson_progress.completed_at, EXCLUDED.completed_at),
                client_updated_at = GREATEST(lesson_progress.client_updated_at, EXCLUDED.client_updated_at),
                device_id = COALESCE(EXCLUDED.device_id, lesson_progress.device_id),
                updated_at = EXCLUDED.updated_at
            SQL, [
            (string) \Illuminate\Support\Str::ulid(),
            $user->id,
            $courseId,
            $lesson->uid,
            $completed ? ProgressStatus::Completed->value : ProgressStatus::InProgress->value,
            $position,
            $completed ? $now : null,
            $clientTime,
            $deviceId,
            $now,
            $now,
        ]);
    }

    /**
     * Lessons by uid from the live versions of courses the user is actively enrolled in.
     *
     * @param  list<string>  $uids
     * @return array<string, array{lesson: Lesson, course_id: string}>
     */
    private function liveLessons(User $user, array $uids): array
    {
        $courseIds = Enrollment::query()->active()->where('user_id', $user->id)->pluck('course_id');
        $courses = Course::query()->whereIn('id', $courseIds)->whereNotNull('live_version_id')->pluck('id', 'live_version_id');

        return Lesson::query()->whereIn('uid', array_unique($uids))->whereIn('course_version_id', $courses->keys())->get()
            ->mapWithKeys(fn (Lesson $l) => [$l->uid => ['lesson' => $l, 'course_id' => (string) $courses[$l->course_version_id]]])
            ->all();
    }
}
