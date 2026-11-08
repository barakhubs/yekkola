<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\CourseAccess;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Learning\QaAccess;

/**
 * Asks a question on a lesson of the live version (PRD-06 FR-13). Students with access and the course's professor
 * may post. Notifying the professor comes with notifications (1.12).
 */
final class PostThread
{
    public function __construct(
        private readonly CourseAccess $courses,
        private readonly QaAccess $qa,
    ) {}

    public function handle(User $author, string $lessonUid, string $title, string $body): LessonThread
    {
        [$lesson, $course] = $this->courses->liveLesson($lessonUid);
        $this->qa->assertParticipant($author, $course);

        return LessonThread::query()->create([
            'course_id' => $course->id,
            'lesson_uid' => $lesson->uid,
            'author_id' => $author->id,
            'title' => $title,
            'body' => $body,
        ]);
    }
}
