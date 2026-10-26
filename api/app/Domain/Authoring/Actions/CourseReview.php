<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Enums\CourseStatus;
use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Authoring\Events\CourseVersionDecided;
use App\Domain\Authoring\Events\CourseVersionSubmitted;
use App\Domain\Authoring\Exceptions\AuthoringException;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\CourseVersion;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * The review state machine of a course's draft (PRD-03 FR-10–12, PRD-09 FR-07):
 * submit → in review → approved (becomes live, previous live superseded) or rejected (editable again);
 * the professor can withdraw while in review. Course and version rows are locked for every transition.
 */
final class CourseReview
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FreeCourseLimits $freeLimits,
        private readonly RecordAudit $recordAudit,
        private readonly Dispatcher $events,
    ) {}

    public function submit(Course $course): CourseVersion
    {
        $version = $this->transition($course, function (Course $locked, CourseVersion $draft): CourseVersion {
            if (! $draft->isEditable()) {
                throw AuthoringException::draftNotEditable();
            }

            if (! $locked->professor->isActive()) {
                throw AuthoringException::professorSuspended();
            }

            $missing = $this->checklist($locked, $draft);
            if ($missing !== []) {
                throw AuthoringException::notReadyForReview($missing);
            }

            if ($locked->is_free) {
                $this->freeLimits->assertCanMakeFree($locked, (int) $draft->lessons()->sum('duration_seconds'));
            }

            $draft->forceFill(['state' => VersionState::InReview, 'submitted_at' => now(), 'review_notes' => null])->save();

            if (! $locked->wasEverPublished()) {
                $locked->forceFill(['status' => CourseStatus::InReview])->save();
            }

            return $draft;
        });

        $this->events->dispatch(new CourseVersionSubmitted($version));

        return $version;
    }

    public function withdraw(Course $course): CourseVersion
    {
        return $this->transition($course, function (Course $locked, CourseVersion $draft): CourseVersion {
            if ($draft->state !== VersionState::InReview) {
                throw AuthoringException::invalidTransition();
            }

            $draft->forceFill(['state' => VersionState::Draft, 'submitted_at' => null])->save();

            if (! $locked->wasEverPublished()) {
                $locked->forceFill(['status' => CourseStatus::Draft])->save();
            }

            return $draft;
        });
    }

    public function approve(CourseVersion $version, User $staff, ?string $note): CourseVersion
    {
        $result = $this->decide($version, $staff, function (Course $locked, CourseVersion $draft) use ($staff, $note): CourseVersion {
            if (! $locked->professor->isActive()) {
                throw AuthoringException::professorSuspended(); // review is paused while suspended (PRD-03 §7)
            }

            if ($locked->live_version_id !== null) {
                CourseVersion::query()->whereKey($locked->live_version_id)->update(['state' => VersionState::Superseded]);
            }

            $draft->forceFill([
                'state' => VersionState::Approved,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'review_notes' => $note,
            ])->save();

            $locked->forceFill([
                'live_version_id' => $draft->id,
                'draft_version_id' => null,
                // An update never changes visibility: unpublished and archived courses stay so.
                'status' => in_array($locked->status, [CourseStatus::Unpublished, CourseStatus::Archived], true) ? $locked->status : CourseStatus::Published,
                'published_at' => $locked->published_at ?? now(),
                'total_duration_seconds' => (int) $draft->lessons()->sum('duration_seconds'),
            ])->save();

            $this->recordAudit->handle('course_version.approved', $draft, ['note' => $note, 'course_id' => $locked->id], causer: $staff);

            return $draft;
        });

        return $result;
    }

    public function reject(CourseVersion $version, User $staff, string $note): CourseVersion
    {
        return $this->decide($version, $staff, function (Course $locked, CourseVersion $draft) use ($staff, $note): CourseVersion {
            $draft->forceFill([
                'state' => VersionState::Rejected,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'review_notes' => $note,
            ])->save();

            if (! $locked->wasEverPublished()) {
                $locked->forceFill(['status' => CourseStatus::Draft])->save();
            }

            $this->recordAudit->handle('course_version.rejected', $draft, ['note' => $note, 'course_id' => $locked->id], causer: $staff);

            return $draft;
        });
    }

    /**
     * Everything missing before a draft can be submitted (FR-10). Empty = ready.
     *
     * @return list<string>
     */
    public function checklist(Course $course, CourseVersion $draft): array
    {
        $lessons = $draft->lessons()->with('mediaAsset', 'quiz.questions', 'media')->get();
        $outcomes = count($draft->outcomes);

        return array_keys(array_filter([
            'title' => blank($draft->title),
            'description' => blank($draft->description),
            'outcomes' => $outcomes < 3 || $outcomes > 8,
            'cover' => $draft->coverUrl() === null,
            'category' => $course->category_id === null,
            'pricing' => ! $course->hasPricing(),
            'sections' => $draft->sections()->count() < 1,
            'lessons' => $lessons->count() < 3,
            'lesson_content' => $lessons->contains(fn (Lesson $l) => ! $l->isContentReady()),
        ]));
    }

    /** In review, and not the reviewer's own course (reject; approving also needs an active professor). */
    public function canDecide(CourseVersion $version, User $staff): bool
    {
        return $version->course !== null
            && $version->course->professor->user_id !== $staff->id
            && $version->state === VersionState::InReview;
    }

    public function canApprove(CourseVersion $version, User $staff): bool
    {
        return $this->canDecide($version, $staff) && $version->course?->professor->isActive() === true;
    }

    /**
     * @param  Closure(Course, CourseVersion): CourseVersion  $apply
     */
    private function decide(CourseVersion $version, User $staff, Closure $apply): CourseVersion
    {
        if ($version->course === null) {
            throw AuthoringException::invalidTransition();
        }

        if ($version->course->professor->user_id === $staff->id) {
            throw AuthoringException::ownCourse();
        }

        if ($version->state !== VersionState::InReview) {
            throw AuthoringException::invalidTransition();
        }

        $result = $this->transition($version->course, function (Course $locked, CourseVersion $draft) use ($version, $apply): CourseVersion {
            if ($draft->id !== $version->id || $draft->state !== VersionState::InReview) {
                throw AuthoringException::invalidTransition();
            }

            return $apply($locked, $draft);
        });

        $this->events->dispatch(new CourseVersionDecided($result));

        return $result;
    }

    /**
     * @param  Closure(Course, CourseVersion): CourseVersion  $apply
     */
    private function transition(Course $course, Closure $apply): CourseVersion
    {
        return $this->db->transaction(function () use ($course, $apply): CourseVersion {
            $locked = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $draft = $locked->draft_version_id === null
                ? null
                : CourseVersion::query()->whereKey($locked->draft_version_id)->lockForUpdate()->first();

            if ($draft === null) {
                throw AuthoringException::noDraft();
            }

            return $apply($locked, $draft);
        });
    }
}
