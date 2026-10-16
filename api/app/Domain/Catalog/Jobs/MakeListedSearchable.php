<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Authoring\Models\Course;
use Laravel\Scout\Jobs\MakeSearchable;

/**
 * Scout's indexing job, re-checking `shouldBeSearchable()` when it runs: courses that stopped being
 * listed since the job was queued (publish then quick unpublish, suspension) are removed instead.
 */
final class MakeListedSearchable extends MakeSearchable
{
    public function handle(): void
    {
        $model = $this->models->first();
        if (! $model instanceof Course) {
            parent::handle();

            return;
        }

        [$listed, $unlisted] = $model->makeSearchableUsing($this->models)->partition(fn (Course $c) => $c->shouldBeSearchable());

        if ($listed->isNotEmpty()) {
            $model->searchableUsing()->update($listed);
        }
        if ($unlisted->isNotEmpty()) {
            $model->searchableUsing()->delete($unlisted);
        }
    }
}
