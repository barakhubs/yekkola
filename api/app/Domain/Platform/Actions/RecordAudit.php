<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Models\Activity;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\ActivityLogger;

/**
 * Writes one audit-log entry for an admin/staff action or a platform change (PRD-09 FR-16).
 *
 * Usage: $recordAudit->handle('course.taken_down', $course, ['reason' => $reason]);
 * The causer is the authenticated user (session or token) unless given explicitly.
 */
final class RecordAudit
{
    public const LOG_NAME = 'audit';

    public function __construct(
        private readonly Container $container,
        private readonly Auth $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $properties  Before/after values, reason, etc. Never secrets or full phone numbers.
     */
    public function handle(string $event, ?Model $subject = null, array $properties = [], ?Model $causer = null, ?string $subjectLabel = null): Activity
    {
        $causer ??= $this->auth->guard('sanctum')->user() ?? $this->auth->guard()->user();

        // ActivityLogger is stateful: take a fresh one per entry.
        $logger = $this->container->make(ActivityLogger::class)
            ->useLog(self::LOG_NAME)
            ->event($event)
            ->withProperties($properties);

        if ($causer instanceof Model) {
            $logger->causedBy($causer);
        }

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        /** @var Activity $activity */
        $activity = $logger->log($event);

        // Non-model subjects (e.g. a settings group) are recorded by label.
        if ($subject === null && $subjectLabel !== null) {
            $activity->forceFill(['subject_type' => $subjectLabel])->save();
        }

        return $activity;
    }
}
