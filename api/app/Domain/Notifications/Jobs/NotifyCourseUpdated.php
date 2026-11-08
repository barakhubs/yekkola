<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Jobs;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Tells every student with access that a course they follow has a new version (PRD-10), in batches. */
final class NotifyCourseUpdated implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $courseId)
    {
        $this->onQueue('notifications');
    }

    public function handle(Notifier $notify): void
    {
        $course = Course::query()->with('liveVersion')->find($this->courseId);
        if ($course === null) {
            return;
        }
        $title = (string) $course->liveVersion?->title;

        Enrollment::query()->active()->where('course_id', $course->id)->select('user_id')->distinct()
            ->orderBy('user_id')
            ->chunk(500, function ($rows) use ($notify, $course, $title): void {
                foreach (User::query()->whereIn('id', $rows->pluck('user_id'))->get() as $user) {
                    $notify->user($user, NotificationType::CourseUpdated, 'course_updated', ['course' => $title], "/learn/{$course->id}");
                }
            });
    }
}
