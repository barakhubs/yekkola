<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\ProfessorAnalytics;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The professor's analytics dashboard (studio): enrollments, sales and what students paid, completions, active
 * students, reviews and quiz results per day, from pre-aggregated daily stats (updated hourly). Earnings — the
 * professor's share — are on the earnings page, from the ledger.
 */
final class AnalyticsController extends Controller
{
    /**
     * All my courses over the last `days` (7, 30, 90 or 365; default 30), with the same span before for comparison,
     * a daily series and a per-course breakdown.
     *
     * @return array{data: array{period_days: int, from: string, to: string, totals: array{enrollments: int, sales: int, completions: int, active_students: int, reviews: int, quiz_attempts: int, quiz_passes: int, revenue: list<array{amount_minor: int, currency: string}>}, previous: array{enrollments: int, sales: int, completions: int, active_students: int, reviews: int, quiz_attempts: int, quiz_passes: int, revenue: list<array{amount_minor: int, currency: string}>}, daily: list<array{date: string, enrollments: int, sales: int, completions: int, active_students: int, reviews: int, quiz_attempts: int, quiz_passes: int, revenue: list<array{amount_minor: int, currency: string}>}>, courses: list<array{course_id: string, title: string|null, status: string, rating: float|null, rating_count: int, enrollments: int, sales: int, completions: int, active_students: int, reviews: int, quiz_attempts: int, quiz_passes: int, revenue: list<array{amount_minor: int, currency: string}>}>}}
     */
    #[\Dedoc\Scramble\Attributes\QueryParameter('days', description: '7, 30, 90 or 365.', type: 'int', default: 30)]
    public function overview(Request $request, ProfessorAnalytics $analytics): array
    {
        $days = $this->days($request);
        $courses = Course::query()->with('liveVersion', 'draftVersion')
            ->where('professor_profile_id', $this->professor($request)->id)
            ->get();

        return ['data' => [
            ...$analytics->summary($courses->pluck('id')->all(), $days),
            'courses' => $analytics->byCourse($courses, $days),
        ]];
    }

    /**
     * One of my courses: the same figures, plus where its students are now (enrolled → started → half-way →
     * completed).
     *
     * @return array{data: array{period_days: int, from: string, to: string, totals: array{enrollments: int, sales: int, completions: int, active_students: int, reviews: int, quiz_attempts: int, quiz_passes: int, revenue: list<array{amount_minor: int, currency: string}>}, previous: array{enrollments: int, sales: int, completions: int, active_students: int, reviews: int, quiz_attempts: int, quiz_passes: int, revenue: list<array{amount_minor: int, currency: string}>}, daily: list<array{date: string, enrollments: int, sales: int, completions: int, active_students: int, reviews: int, quiz_attempts: int, quiz_passes: int, revenue: list<array{amount_minor: int, currency: string}>}>, funnel: array{enrolled: int, started: int, halfway: int, completed: int}}}
     */
    #[\Dedoc\Scramble\Attributes\QueryParameter('days', description: '7, 30, 90 or 365.', type: 'int', default: 30)]
    public function course(Request $request, string $course, ProfessorAnalytics $analytics): array
    {
        $model = Course::query()->where('professor_profile_id', $this->professor($request)->id)->findOrFail($course);

        return ['data' => [
            ...$analytics->summary([$model->id], $this->days($request)),
            'funnel' => $analytics->funnel($model->id),
        ]];
    }

    private function days(Request $request): int
    {
        $request->validate(['days' => ['sometimes', 'integer', Rule::in(ProfessorAnalytics::PERIODS)]]);

        return $request->integer('days', 30);
    }

    private function professor(Request $request): ProfessorProfile
    {
        /** @var ProfessorProfile */
        return $request->attributes->get('professor_profile');
    }
}
