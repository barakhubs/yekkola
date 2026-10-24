<?php

declare(strict_types=1);

namespace App\Domain\Protection\Actions;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\CourseAccess;
use App\Domain\Protection\Enums\DocumentStampStatus;
use App\Domain\Protection\Exceptions\ProtectionException;
use App\Domain\Protection\Jobs\StampDocument;
use App\Domain\Protection\Models\DocumentStamp;

/**
 * A student's stamped copy of a document lesson (PRD-07 FR-12): enrolled students, or anyone signed in for
 * a preview lesson. The copy is made once per student and source PDF, on the queue; until it is ready the
 * caller polls.
 */
final class RequestDocument
{
    public function __construct(private readonly CourseAccess $access) {}

    public function handle(User $user, string $lessonUid): DocumentStamp
    {
        [$lesson] = $this->access->lesson($user, $lessonUid, allowPreview: true);
        if ($lesson->type !== LessonType::Document) {
            throw ProtectionException::notADocument();
        }

        $source = $lesson->getFirstMedia(Lesson::DOCUMENT) ?? throw ProtectionException::documentUnavailable();

        $stamp = DocumentStamp::query()->createOrFirst(
            ['user_id' => $user->id, 'source_media_id' => $source->id],
            ['status' => DocumentStampStatus::Pending],
        );

        if ($stamp->status === DocumentStampStatus::Failed) {
            throw ProtectionException::documentUnavailable();
        }
        if ($stamp->wasRecentlyCreated) {
            StampDocument::dispatch($stamp->id);
        }

        return $stamp->refresh();
    }
}
