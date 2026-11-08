<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Models;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Learning\Models\Review;
use App\Domain\Learning\Models\ThreadReply;
use App\Domain\Moderation\Enums\ReportAction;
use App\Domain\Moderation\Enums\ReportReason;
use App\Domain\Moderation\Enums\ReportStatus;
use App\Domain\Moderation\Enums\ReportTarget;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's report of a course, review or professor (PRD-06 FR-14, PRD-09 FR-10). The item is named by
 * `reportable_type` (a {@see ReportTarget}) and its id; staff resolve every open report on an item together.
 *
 * @property string $id
 * @property string|null $reporter_id
 * @property ReportTarget $reportable_type
 * @property string $reportable_id
 * @property ReportReason $reason
 * @property string|null $details
 * @property ReportStatus $status
 * @property ReportAction|null $action
 * @property string|null $handled_by
 * @property string|null $resolution_note
 * @property \Illuminate\Support\Carbon|null $handled_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class Report extends Model
{
    use HasUlids;

    /** Reports still open on the same item; set by {@see self::loadTargets()} (not a column). */
    public int $openReportsOnTarget = 0;

    private bool $targetLoaded = false;

    private Course|Review|LessonThread|ThreadReply|ProfessorProfile|null $loadedTarget = null;

    protected $guarded = [];

    protected $attributes = ['status' => 'open'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reportable_type' => ReportTarget::class,
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'action' => ReportAction::class,
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function isOpen(): bool
    {
        return $this->status === ReportStatus::Open;
    }

    /** The reported item (deleted ones included), or null when it no longer exists at all. */
    public function target(): Course|Review|LessonThread|ThreadReply|ProfessorProfile|null
    {
        if (! $this->targetLoaded) {
            self::loadTargets(new Collection([$this]));
        }

        return $this->loadedTarget;
    }

    /**
     * Loads each report's item (one query per kind) and how many reports are still open on it, for lists.
     *
     * @template TReport of self
     *
     * @param  Collection<int, TReport>  $reports
     * @return Collection<int, TReport>
     */
    public static function loadTargets(Collection $reports): Collection
    {
        $ids = fn (ReportTarget $type): array => $reports->where('reportable_type', $type)->pluck('reportable_id')->unique()->values()->all();
        $found = [
            ReportTarget::Course->value => Course::withTrashed()->with('professor.user.roles', 'liveVersion')->findMany($ids(ReportTarget::Course))->keyBy('id'),
            ReportTarget::Review->value => Review::query()->with('user.roles', 'course.liveVersion')->findMany($ids(ReportTarget::Review))->keyBy('id'),
            ReportTarget::Thread->value => LessonThread::query()->with('author.roles', 'course.liveVersion')->findMany($ids(ReportTarget::Thread))->keyBy('id'),
            ReportTarget::Reply->value => ThreadReply::query()->with('author.roles', 'thread.course.liveVersion')->findMany($ids(ReportTarget::Reply))->keyBy('id'),
            ReportTarget::Professor->value => ProfessorProfile::query()->with('user.roles')->findMany($ids(ReportTarget::Professor))->keyBy('id'),
        ];
        $open = self::query()
            ->where('status', ReportStatus::Open)
            ->whereIn('reportable_id', $reports->pluck('reportable_id')->unique()->all())
            ->groupBy('reportable_type', 'reportable_id')
            ->selectRaw('reportable_type, reportable_id, count(*) as open_count')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [((string) $row->reportable_type).':'.((string) $row->reportable_id) => (int) $row->open_count]);

        foreach ($reports as $report) {
            $report->loadedTarget = $found[$report->reportable_type->value]->get($report->reportable_id);
            $report->targetLoaded = true;
            $report->openReportsOnTarget = $open->get($report->reportable_type->value.':'.$report->reportable_id, 0);
        }

        return $reports;
    }
}
