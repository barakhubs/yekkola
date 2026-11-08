<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\ReviewStatus;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Learning\Models\Review;
use App\Domain\Learning\Models\ThreadReply;
use App\Domain\Learning\QaAccess;
use App\Domain\Moderation\Enums\ReportReason;
use App\Domain\Moderation\Enums\ReportStatus;
use App\Domain\Moderation\Enums\ReportTarget;
use App\Domain\Moderation\Exceptions\ModerationException;
use App\Domain\Moderation\Models\Report;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A signed-in user reports a course, review or professor the public can see (PRD-06 FR-14). Nobody reports
 * their own content. Reporting an item again while the first report is open returns that report unchanged, so
 * one person can't stack reports on an item.
 */
final class FileReport
{
    public function __construct(private readonly QaAccess $qa) {}

    public function handle(User $reporter, ReportTarget $type, string $id, ReportReason $reason, ?string $details): Report
    {
        $authorId = $this->visibleAuthor($type, $id, $reporter);
        if ($authorId === null) {
            throw ModerationException::targetNotFound();
        }
        if ($authorId === $reporter->id) {
            throw ModerationException::ownContent();
        }

        $existing = $this->openReport($reporter, $type, $id);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return Report::query()->create([
                'reporter_id' => $reporter->id,
                'reportable_type' => $type,
                'reportable_id' => $id,
                'reason' => $reason,
                'details' => $details,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two taps at once: the other request filed it.
            return $this->openReport($reporter, $type, $id) ?? throw ModerationException::targetNotFound();
        }
    }

    /**
     * The author's user id when the reporter can see the item, null otherwise (unknown, hidden, taken down…). Q&A
     * is seen by the course's participants only.
     */
    private function visibleAuthor(ReportTarget $type, string $id, User $reporter): ?string
    {
        return match ($type) {
            ReportTarget::Course => Course::query()->listed()->with('professor')->find($id)?->professor?->user_id,
            ReportTarget::Review => Review::query()->where('status', ReviewStatus::Visible)
                ->whereHas('course', fn ($q) => $q->listed())->find($id)?->user_id,
            ReportTarget::Professor => ProfessorProfile::query()->public()->find($id)?->user_id,
            ReportTarget::Thread => $this->participantSees(LessonThread::query()->whereNull('hidden_at')
                ->whereHas('author', fn ($q) => $q->where('status', '!=', UserStatus::Banned))->with('course')->find($id), $reporter),
            ReportTarget::Reply => $this->participantSees(ThreadReply::query()->whereNull('hidden_at')
                ->whereHas('author', fn ($q) => $q->where('status', '!=', UserStatus::Banned))
                ->whereHas('thread', fn ($q) => $q->whereNull('hidden_at'))->with('thread.course')->find($id), $reporter),
        };
    }

    private function participantSees(LessonThread|ThreadReply|null $post, User $reporter): ?string
    {
        $course = $post instanceof ThreadReply ? $post->thread?->course : $post?->course;
        try {
            if ($post === null || $course === null || ! $this->qa->isParticipant($reporter, $course)) {
                return null;
            }
        } catch (LearningException) {
            return null; // a taken-down course: nothing there to report
        }

        return $post->author_id;
    }

    private function openReport(User $reporter, ReportTarget $type, string $id): ?Report
    {
        return Report::query()
            ->where('reporter_id', $reporter->id)
            ->where('reportable_type', $type)
            ->where('reportable_id', $id)
            ->where('status', ReportStatus::Open)
            ->first();
    }
}
