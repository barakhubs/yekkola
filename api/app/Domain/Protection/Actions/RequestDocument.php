<?php

declare(strict_types=1);

namespace App\Domain\Protection\Actions;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\CourseAccess;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Protection\Enums\DocumentStampStatus;
use App\Domain\Protection\Exceptions\ProtectionException;
use App\Domain\Protection\Jobs\StampDocument;
use App\Domain\Protection\Models\DocumentStamp;

/**
 * A student's stamped copy of a document lesson (PRD-07 FR-12): enrolled students, or anyone signed in for
 * a preview lesson of a listed course. The copy is made once per student and source PDF, on the queue;
 * until it is ready the caller polls. A copy that waited too long (lost job) is queued again.
 */
final class RequestDocument
{
    /** A pending copy older than this is assumed lost and queued again — longer than the job's unique lock. */
    private const STALE_PENDING_MINUTES = 15;

    public function __construct(private readonly CourseAccess $access) {}

    public function handle(User $user, string $lessonUid): DocumentStamp
    {
        [$lesson, $course] = $this->access->liveLesson($lessonUid);
        if ($this->access->activeEnrollment($user, $course->id) === null && ! $this->access->previewAllowed($lesson, $course)) {
            throw LearningException::enrollmentRequired();
        }
        if ($lesson->type !== LessonType::Document) {
            throw ProtectionException::notADocument();
        }

        $source = $lesson->getFirstMedia(Lesson::DOCUMENT) ?? throw ProtectionException::documentUnavailable();

        $stamp = DocumentStamp::query()->createOrFirst(
            ['user_id' => $user->id, 'source_media_id' => $source->id],
            ['status' => DocumentStampStatus::Pending],
        );

        $stale = $stamp->status === DocumentStampStatus::Pending && $stamp->updated_at->lt(now()->subMinutes(self::STALE_PENDING_MINUTES));
        if ($stamp->wasRecentlyCreated || $stale) {
            $stamp->touch();
            StampDocument::dispatch($stamp->id);
            $stamp = $stamp->fresh() ?? $stamp; // a sync queue has already run it
        }

        if ($stamp->status === DocumentStampStatus::Failed) {
            throw ProtectionException::documentUnavailable();
        }

        return $stamp;
    }
}
