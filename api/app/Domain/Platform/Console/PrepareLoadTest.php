<?php

declare(strict_types=1);

namespace App\Domain\Platform\Console;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\EnrollInFreeCourse;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Learning\Models\Enrollment;
use Illuminate\Console\Command;

/**
 * Creates signed-in test students for the k6 load tests (TODO 1.13, `loadtest/README.md`) and prints what the
 * scripts need as JSON: bearer tokens, a free course they're enrolled in with its lessons, and a paid course to
 * check out. Students sign in with tokens rather than SMS codes so the run doesn't spend the OTP budget.
 * Staging and local only; their phone numbers (+243 990 1…) are reused on the next run, and end in digits the
 * fake payment gateway treats as plain success (its scenarios use …0001–…0010).
 */
final class PrepareLoadTest extends Command
{
    protected $signature = 'loadtest:prepare {--students=100 : How many test students}';

    protected $description = 'Create test students with tokens for the k6 load tests (not in production)';

    public function handle(EnrollInFreeCourse $enroll): int
    {
        if (app()->isProduction()) {
            $this->error('Load-test students are never created in production.');

            return self::FAILURE;
        }

        $free = Course::query()->listed()->where('is_free', true)->orderByDesc('enrollment_count')->first();
        $paid = Course::query()->listed()->where('is_free', false)->whereNotNull('price_minor')->whereNotNull('currency')->first();
        if ($free === null || $paid === null) {
            $this->error('Needs at least one listed free course and one listed paid course (seed the demo catalogue).');

            return self::FAILURE;
        }

        $lessons = Lesson::query()->where('course_version_id', $free->live_version_id)
            ->whereIn('type', [LessonType::Video, LessonType::Audio])->pluck('uid')->all();
        $allLessons = Lesson::query()->where('course_version_id', $free->live_version_id)->pluck('uid')->all();

        $tokens = [];
        $count = max(1, min(5_000, (int) $this->option('students')));
        for ($i = 1; $i <= $count; $i++) {
            $user = User::query()->firstOrCreate(
                ['phone_e164' => '+243990'.(100_000 + $i)],
                ['name' => "Load test {$i}", 'locale' => 'fr'],
            );
            $user->forceFill(['phone_verified_at' => $user->phone_verified_at ?? now()])->save();
            $user->tokens()->where('name', 'loadtest')->delete();
            $tokens[] = $user->createToken('loadtest', ['*'], now()->addDay())->plainTextToken;
            if (! Enrollment::query()->where('user_id', $user->id)->where('course_id', $free->id)->exists()) {
                try {
                    $enroll->handle($user, $free->id);
                } catch (LearningException) {
                    // e.g. an enrolment revoked earlier: the student still browses and checks out.
                }
            }
        }

        $this->line((string) json_encode([
            'tokens' => $tokens,
            'free_course' => ['id' => $free->id, 'slug' => $free->slug, 'media_lessons' => $lessons, 'lessons' => $allLessons],
            'paid_course' => ['id' => $paid->id, 'slug' => $paid->slug],
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
