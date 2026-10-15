<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Exceptions\AuthoringException;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\CourseVersion;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\Section;
use App\Domain\Platform\Settings\CatalogSettings;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Edits a course's draft version (PRD-03 FR-02/03/08): details, cover, sections, lessons, and ordering.
 * All edits go through one class because they share the same guard — the draft is locked and must be
 * editable (draft or rejected); a version in review or live is never changed.
 */
final class EditDraft
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CatalogSettings $catalog,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated: title, subtitle, description, outcomes, requirements, changelog.
     */
    public function updateDetails(Course $course, array $data): CourseVersion
    {
        return $this->withDraft($course, function (CourseVersion $draft) use ($data): CourseVersion {
            $draft->fill($data)->save();

            return $draft;
        });
    }

    public function setCover(Course $course, UploadedFile $file): CourseVersion
    {
        return $this->withDraft($course, function (CourseVersion $draft) use ($file): CourseVersion {
            $draft->addMedia($file)->usingFileName('cover.'.$file->extension())->toMediaCollection(CourseVersion::COVER);

            return $draft;
        });
    }

    public function addSection(Course $course, string $title): Section
    {
        return $this->withDraft($course, fn (CourseVersion $draft): Section => $draft->sections()->create([
            'title' => $title,
            'position' => (int) $draft->sections()->max('position') + 1,
        ]));
    }

    public function updateSection(Course $course, string $sectionId, string $title): Section
    {
        return $this->withDraft($course, function (CourseVersion $draft) use ($sectionId, $title): Section {
            $section = $draft->sections()->findOrFail($sectionId);
            $section->update(['title' => $title]);

            return $section;
        });
    }

    public function deleteSection(Course $course, string $sectionId): void
    {
        $this->withDraft($course, fn (CourseVersion $draft) => $draft->sections()->findOrFail($sectionId)->delete());
    }

    /**
     * @param  array{section_id: string, type: string, title: string, description?: string|null, is_preview?: bool}  $data
     */
    public function addLesson(Course $course, array $data): Lesson
    {
        return $this->withDraft($course, function (CourseVersion $draft) use ($data): Lesson {
            $section = $draft->sections()->findOrFail($data['section_id']);

            if (($data['is_preview'] ?? false) === true) {
                $this->assertPreviewRoom($draft, null);
            }

            $lesson = $section->lessons()->create([
                'course_version_id' => $draft->id,
                'uid' => (string) Str::ulid(),
                'type' => LessonType::from($data['type']),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'is_preview' => $data['is_preview'] ?? false,
                'position' => (int) $section->lessons()->max('position') + 1,
            ]);

            if ($lesson->type === LessonType::Quiz) {
                $lesson->quiz()->create([]);
            }

            return $lesson;
        });
    }

    /**
     * @param  array{title?: string, description?: string|null, is_preview?: bool}  $data
     */
    public function updateLesson(Course $course, string $lessonId, array $data): Lesson
    {
        return $this->withDraft($course, function (CourseVersion $draft) use ($lessonId, $data): Lesson {
            $lesson = $draft->lessons()->findOrFail($lessonId);

            if (($data['is_preview'] ?? false) === true && ! $lesson->is_preview) {
                $this->assertPreviewRoom($draft, $lesson->id);
            }

            $lesson->update($data);

            return $lesson;
        });
    }

    public function deleteLesson(Course $course, string $lessonId): void
    {
        $this->withDraft($course, fn (CourseVersion $draft) => $draft->lessons()->findOrFail($lessonId)->delete());
    }

    /**
     * Full curriculum order: sections in order, each with its lessons in order. Lessons may move between
     * sections. Every section and lesson of the draft must appear exactly once.
     *
     * @param  list<array{id: string, lessons: list<string>}>  $order
     */
    public function reorder(Course $course, array $order): CourseVersion
    {
        return $this->withDraft($course, function (CourseVersion $draft) use ($order): CourseVersion {
            $sectionIds = $draft->sections()->pluck('id')->sort()->values()->all();
            $lessonIds = $draft->lessons()->pluck('id')->sort()->values()->all();

            $givenSections = collect($order)->pluck('id');
            $givenLessons = collect($order)->pluck('lessons')->flatten();

            if ($givenSections->sort()->values()->all() !== $sectionIds
                || $givenLessons->sort()->values()->all() !== $lessonIds
                || $givenSections->duplicates()->isNotEmpty()
                || $givenLessons->duplicates()->isNotEmpty()) {
                throw AuthoringException::invalidOrder();
            }

            foreach ($order as $sectionPosition => $entry) {
                Section::query()->whereKey($entry['id'])->update(['position' => $sectionPosition + 1]);

                foreach ($entry['lessons'] as $lessonPosition => $lessonId) {
                    Lesson::query()->whereKey($lessonId)->update([
                        'section_id' => $entry['id'],
                        'position' => $lessonPosition + 1,
                    ]);
                }
            }

            return $draft;
        });
    }

    /**
     * Runs $edit on the course's draft, locked, after checking it's editable.
     *
     * @template T
     *
     * @param  Closure(CourseVersion): T  $edit
     * @return T
     */
    public function withDraft(Course $course, Closure $edit): mixed
    {
        return $this->db->transaction(function () use ($course, $edit) {
            $draft = $course->draft_version_id === null
                ? null
                : CourseVersion::query()->whereKey($course->draft_version_id)->lockForUpdate()->first();

            if ($draft === null) {
                throw AuthoringException::noDraft();
            }

            if (! $draft->isEditable()) {
                throw AuthoringException::draftNotEditable();
            }

            $result = $edit($draft);
            $draft->touch();

            return $result;
        });
    }

    private function assertPreviewRoom(CourseVersion $draft, ?string $exceptLessonId): void
    {
        $max = $this->catalog->max_preview_lessons;
        $count = $draft->lessons()->where('is_preview', true)
            ->when($exceptLessonId !== null, fn ($q) => $q->whereKeyNot($exceptLessonId))
            ->count();

        if ($count >= $max) {
            throw AuthoringException::previewLimit($max);
        }
    }
}
