<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Authoring\Exceptions\AuthoringException;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\CourseVersion;
use App\Domain\Authoring\Models\Lesson;
use Illuminate\Database\ConnectionInterface;

/**
 * "Edit live course" (PRD-03 FR-13): copies the live version into a new draft. Lessons keep their `uid`
 * (progress and Q&A follow), media assets are reused (no re-upload), documents, cover, and quizzes are copied.
 * Students keep seeing the live version until the draft is approved.
 */
final class StartDraftFromLive
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /** A live course without a draft in progress. */
    public function canStart(Course $course): bool
    {
        return $course->draft_version_id === null && $course->live_version_id !== null;
    }

    public function handle(Course $course): CourseVersion
    {
        return $this->db->transaction(function () use ($course): CourseVersion {
            $locked = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();

            if ($locked->draft_version_id !== null) {
                throw AuthoringException::draftExists();
            }

            $live = $locked->liveVersion ?? throw AuthoringException::notPublished();
            $live->load('sections.lessons.quiz.questions.options');

            $draft = $locked->versions()->create([
                'number' => (int) $locked->versions()->max('number') + 1,
                'state' => VersionState::Draft,
                'title' => $live->title,
                'subtitle' => $live->subtitle,
                'description' => $live->description,
                'outcomes' => $live->outcomes,
                'requirements' => $live->requirements,
            ]);

            $live->getFirstMedia(CourseVersion::COVER)?->copy($draft, CourseVersion::COVER, (string) config('yekkola.public_disk'));

            foreach ($live->sections as $section) {
                $newSection = $draft->sections()->create(['title' => $section->title, 'position' => $section->position]);

                foreach ($section->lessons as $lesson) {
                    $this->copyLesson($lesson, $draft->id, $newSection->id);
                }
            }

            $locked->forceFill(['draft_version_id' => $draft->id])->save();

            return $draft;
        });
    }

    private function copyLesson(Lesson $lesson, string $versionId, string $sectionId): void
    {
        $copy = $lesson->replicate(['id', 'created_at', 'updated_at']);
        $copy->forceFill(['course_version_id' => $versionId, 'section_id' => $sectionId])->save();

        $lesson->getFirstMedia(Lesson::DOCUMENT)?->copy($copy, Lesson::DOCUMENT, (string) config('yekkola.private_disk'));

        if ($lesson->quiz !== null) {
            $quiz = $lesson->quiz->replicate(['id', 'lesson_id', 'created_at', 'updated_at']);
            $quiz->forceFill(['lesson_id' => $copy->id])->save();

            foreach ($lesson->quiz->questions as $question) {
                $newQuestion = $question->replicate(['id', 'quiz_id', 'created_at', 'updated_at']);
                $newQuestion->forceFill(['quiz_id' => $quiz->id])->save();

                foreach ($question->options as $option) {
                    $newOption = $option->replicate(['id', 'quiz_question_id', 'created_at', 'updated_at']);
                    $newOption->forceFill(['quiz_question_id' => $newQuestion->id])->save();
                }
            }
        }
    }
}
