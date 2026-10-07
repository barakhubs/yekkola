<?php

declare(strict_types=1);

namespace App\Domain\Platform\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Certificates and reviews (PRD-06).
 */
final class LearningSettings extends Settings
{
    public int $certificate_min_completion_pct;

    public bool $certificate_requires_quizzes_passed;

    /** Progress a student needs before posting a review. */
    public int $review_min_progress_pct;

    public static function group(): string
    {
        return 'learning';
    }
}
