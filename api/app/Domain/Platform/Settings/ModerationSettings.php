<?php

declare(strict_types=1);

namespace App\Domain\Platform\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Review queues and professor applications (PRD-02, PRD-09).
 */
final class ModerationSettings extends Settings
{
    public int $course_review_sla_hours;

    public int $application_review_sla_hours;

    public int $application_reapply_cooldown_days;

    public static function group(): string
    {
        return 'moderation';
    }
}
