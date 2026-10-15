<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the featured list with the given courses, in order (PRD-04 BR-03, PRD-09). Only listed
 * courses can be featured. Audited.
 */
final class SetFeaturedCourses
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * @param  list<string>  $courseIds
     */
    public function handle(array $courseIds, User $staff): void
    {
        $this->db->transaction(function () use ($courseIds, $staff): void {
            $listed = Course::query()->listed()->whereIn('id', $courseIds)->lockForUpdate()->pluck('id')->all();
            if (count($listed) !== count($courseIds)) {
                throw ValidationException::withMessages(['course_ids' => __('catalog.featured.not_listed')]);
            }

            $previous = Course::query()->whereNotNull('featured_position')->orderBy('featured_position')->pluck('id')->all();
            Course::query()->whereNotNull('featured_position')->update(['featured_position' => null]);

            foreach ($courseIds as $position => $id) {
                Course::query()->whereKey($id)->update(['featured_position' => $position + 1]);
            }

            $this->recordAudit->handle('catalog.featured_updated', null, ['before' => $previous, 'after' => $courseIds], causer: $staff);
        });
    }
}
