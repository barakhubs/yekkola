<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Learning\Models\Review;
use App\Domain\Learning\Models\ThreadReply;
use App\Domain\Moderation\Actions\ResolveReport;
use App\Domain\Moderation\Models\Report;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A report in the moderation queue (PRD-09 FR-10): who reported what and why, the item as it is now, how many
 * reports are open on it, the decision once made, and what this staff member may do (`can`).
 *
 * @mixin Report
 */
final class AdminReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $staff */
        $staff = $request->user();
        $target = $this->target();

        return [
            'id' => $this->id,
            'reason' => $this->reason->value,
            'details' => $this->details,
            'status' => $this->status->value,
            'action' => $this->action?->value,
            'reporter' => $this->reporter_id === null ? null : ['id' => $this->reporter_id, 'name' => $this->reporter?->name],
            'target' => $this->targetSummary($target),
            'open_reports_on_target' => $this->openReportsOnTarget,
            'handled_by' => $this->handled_by === null ? null : ['id' => $this->handled_by, 'name' => $this->handler?->name],
            'resolution_note' => $this->resolution_note,
            'handled_at' => $this->handled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            /** @var array{dismiss: bool, hide: bool, warn: bool, suspend: bool} */
            'can' => ResolveReport::allowedActions($this->resource, $target, $staff),
        ];
    }

    /**
     * @return array{type: string, id: string, exists: bool, title: string|null, excerpt: string|null, rating: int|null, course_slug: string|null, professor_slug: string|null, state: string|null, author: array{id: string, name: string|null, status: string}|null}
     */
    private function targetSummary(Course|Review|LessonThread|ThreadReply|ProfessorProfile|null $target): array
    {
        $author = ResolveReport::author($target);
        $course = match (true) {
            $target instanceof Course => $target,
            $target instanceof Review, $target instanceof LessonThread => $target->course,
            $target instanceof ThreadReply => $target->thread?->course,
            default => null,
        };

        return [
            'type' => $this->reportable_type->value,
            'id' => $this->reportable_id,
            'exists' => $target !== null,
            'title' => match (true) {
                $target instanceof ProfessorProfile => $target->display_name,
                $target instanceof LessonThread => $target->title,
                $target instanceof ThreadReply => $target->thread?->title,
                default => $course?->liveVersion?->title,
            },
            'excerpt' => match (true) {
                $target instanceof Review => $target->body !== null ? Str::limit($target->body, 280) : null,
                $target instanceof LessonThread, $target instanceof ThreadReply => Str::limit($target->body, 280),
                default => null,
            },
            'rating' => $target instanceof Review ? $target->rating : null,
            'course_slug' => $course?->slug,
            'professor_slug' => match (true) {
                $target instanceof ProfessorProfile => $target->slug,
                $target instanceof Course => $target->professor?->slug,
                default => null,
            },
            // review: visible|hidden; course: published|unpublished|taken_down|deleted|…; professor: its status.
            'state' => match (true) {
                $target instanceof Review => $target->status->value,
                $target instanceof LessonThread, $target instanceof ThreadReply => $target->isHidden() ? 'hidden' : 'visible',
                $target instanceof Course => $target->trashed() ? 'deleted' : ($target->taken_down_at !== null ? 'taken_down' : $target->status->value),
                $target instanceof ProfessorProfile => $target->status->value,
                default => null,
            },
            'author' => $author === null ? null : ['id' => $author->id, 'name' => $author->name, 'status' => $author->status->value],
        ];
    }
}
