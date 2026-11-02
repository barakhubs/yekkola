<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\ReviewStatus;
use App\Domain\Learning\Models\Review;
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
    public function handle(User $reporter, ReportTarget $type, string $id, ReportReason $reason, ?string $details): Report
    {
        $authorId = $this->visibleAuthor($type, $id);
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

    /** The author's user id when the item is publicly visible, null otherwise (unknown, hidden, taken down…). */
    private function visibleAuthor(ReportTarget $type, string $id): ?string
    {
        return match ($type) {
            ReportTarget::Course => Course::query()->listed()->with('professor')->find($id)?->professor?->user_id,
            ReportTarget::Review => Review::query()->where('status', ReviewStatus::Visible)
                ->whereHas('course', fn ($q) => $q->listed())->find($id)?->user_id,
            ReportTarget::Professor => ProfessorProfile::query()->public()->find($id)?->user_id,
        };
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
