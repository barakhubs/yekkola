<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Audit log entry with a ULID id (configured as `activitylog.activity_model`). Never updated or deleted by the app.
 */
final class Activity extends SpatieActivity
{
    use HasUlids;
}
