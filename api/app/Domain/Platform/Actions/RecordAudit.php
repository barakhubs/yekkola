<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Models\Activity;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\Activitylog\ActivityLogger;

/**
 * Writes one audit-log entry for an admin/staff action or a platform change (PRD-09 FR-16):
 * actor, target, event, before/after or reason in properties, plus the request's IP and user agent.
 *
 * Usage: $recordAudit->handle('course.taken_down', $course, ['reason' => $reason]);
 * The causer is the authenticated user (session or token) unless given explicitly; console actions have none.
 * Non-model targets (e.g. a settings group) go in $properties, never as a fake subject.
 */
final class RecordAudit
{
    public const LOG_NAME = 'audit';

    public function __construct(
        private readonly Application $app,
        private readonly Auth $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $properties  Before/after values, reason, etc. Never secrets or full phone numbers.
     */
    public function handle(string $event, ?Model $subject = null, array $properties = [], ?Model $causer = null): Activity
    {
        $causer ??= $this->auth->guard('sanctum')->user() ?? $this->auth->guard()->user();

        // ActivityLogger is stateful: take a fresh one per entry.
        $logger = $this->app->make(ActivityLogger::class)
            ->useLog(self::LOG_NAME)
            ->event($event)
            ->withProperties([...$properties, 'context' => $this->requestContext()]);

        if ($causer instanceof Model) {
            $logger->causedBy($causer);
        }

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        $activity = $logger->log($event);

        if (! $activity instanceof Activity) {
            throw new LogicException('Audit logging is disabled; it must always be on (config/activitylog.php).');
        }

        return $activity;
    }

    /**
     * @return array{ip: string|null, user_agent: string|null}
     */
    private function requestContext(): array
    {
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return ['ip' => null, 'user_agent' => null];
        }

        $request = $this->app->make('request');

        return ['ip' => $request->ip(), 'user_agent' => $request->userAgent()];
    }
}
