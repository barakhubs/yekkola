<?php

declare(strict_types=1);

namespace App\Domain\Learning\Listeners;

use App\Domain\Learning\Actions\IssueCertificate;
use App\Domain\Learning\Events\ProgressRecorded;
use App\Domain\Learning\Models\Certificate;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Settings\LearningSettings;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Applies the completion rule whenever progress changes (PRD-06 FR-10). Queued — issuing is never on the
 * sync path — and only for enrollments past the threshold without a valid certificate.
 */
final class IssueCertificateOnProgress implements ShouldQueue
{
    public function __construct(private readonly IssueCertificate $issue, private readonly LearningSettings $settings) {}

    public function shouldQueue(ProgressRecorded $event): bool
    {
        $enrollment = Enrollment::query()->find($event->enrollmentId, ['id', 'user_id', 'course_id', 'progress_pct']);

        return $enrollment !== null
            && $enrollment->progress_pct >= $this->settings->certificate_min_completion_pct
            && ! Certificate::query()->valid()->where('user_id', $enrollment->user_id)->where('course_id', $enrollment->course_id)->exists();
    }

    public function handle(ProgressRecorded $event): void
    {
        $this->issue->handle($event->enrollmentId);
    }
}
