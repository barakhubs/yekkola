<?php

declare(strict_types=1);

namespace App\Domain\Authoring;

use App\Domain\Authoring\Models\Course;
use App\Domain\Learning\Enums\EnrollmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the professor analytics dashboard from `course_daily_stats` (rolled up hourly), never from the raw tables
 * — except the per-course funnel, a small aggregate over that course's enrollments. Courses are the ones the
 * professor owns now; money is per currency (no conversion).
 */
final class ProfessorAnalytics
{
    public const PERIODS = [7, 30, 90, 365];

    private const COUNTS = ['enrollments', 'sales', 'completions', 'active_students', 'reviews', 'quiz_attempts', 'quiz_passes'];

    /**
     * @param  list<string>  $courseIds
     * @return array{period_days: int, from: string, to: string, totals: array{enrollments: int, sales: int, completions: int, active_students: int, reviews: int, quiz_attempts: int, quiz_passes: int, revenue: list<array{amount_minor: int, currency: string}>}, previous: array{enrollments: int, sales: int, completions: int, active_students: int, reviews: int, quiz_attempts: int, quiz_passes: int, revenue: list<array{amount_minor: int, currency: string}>}, changes: array{enrollments: int|null, sales: int|null, completions: int|null, active_students: int|null}, quiz_pass_pct: int|null, daily: list<array{date: string, enrollments: int, sales: int, completions: int, active_students: int, reviews: int, quiz_attempts: int, quiz_passes: int, revenue: list<array{amount_minor: int, currency: string}>}>}
     */
    public function summary(array $courseIds, int $days): array
    {
        $to = CarbonImmutable::today();
        $from = $to->subDays($days - 1);
        $previousFrom = $from->subDays($days);

        $rows = $courseIds === [] ? collect() : DB::table('course_daily_stats')
            ->whereIn('course_id', $courseIds)
            ->where('date', '>=', $previousFrom->toDateString())
            ->where('date', '<=', $to->toDateString())
            ->get();
        $current = $rows->filter(fn ($r) => $r->date >= $from->toDateString());
        $previous = $rows->filter(fn ($r) => $r->date < $from->toDateString());

        $daily = [];
        $byDate = $current->groupBy('date');
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $daily[] = ['date' => $date, ...$this->totals($byDate->get($date, collect()))];
        }

        $totals = $this->totals($current);
        $before = $this->totals($previous);
        $totals['active_students'] = $this->distinctActive($courseIds, $from, $to)[''] ?? 0;
        $before['active_students'] = $this->distinctActive($courseIds, $previousFrom, $from->subDay())[''] ?? 0;

        return [
            'period_days' => $days,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $totals,
            'previous' => $before,
            'changes' => [
                'enrollments' => self::changePct($totals['enrollments'], $before['enrollments']),
                'sales' => self::changePct($totals['sales'], $before['sales']),
                'completions' => self::changePct($totals['completions'], $before['completions']),
                'active_students' => self::changePct($totals['active_students'], $before['active_students']),
            ],
            'quiz_pass_pct' => $totals['quiz_attempts'] === 0 ? null : (int) round($totals['quiz_passes'] * 100 / $totals['quiz_attempts']),
            'daily' => $daily,
        ];
    }

    /**
     * Per course over the period, most enrollments first.
     *
     * @param  Collection<int, Course>  $courses
     * @return list<array{course_id: string, title: string|null, status: string, rating: float|null, rating_count: int, enrollments: int, sales: int, completions: int, active_students: int, reviews: int, quiz_attempts: int, quiz_passes: int, revenue: list<array{amount_minor: int, currency: string}>}>
     */
    public function byCourse(Collection $courses, int $days): array
    {
        $today = CarbonImmutable::today();
        $from = $today->subDays($days - 1);
        $rows = $courses->isEmpty() ? collect() : DB::table('course_daily_stats')
            ->whereIn('course_id', $courses->pluck('id'))
            ->where('date', '>=', $from->toDateString())
            ->get()->groupBy('course_id');
        $active = $this->distinctActive($courses->pluck('id')->all(), $from, $today, perCourse: true);

        return $courses
            ->map(fn (Course $c): array => [
                'course_id' => $c->id,
                'title' => $c->liveVersion->title ?? $c->draftVersion?->title,
                'status' => $c->status->value,
                // Same threshold as the public course page (PRD-04 BR-02).
                'rating' => $c->publicRating(),
                'rating_count' => $c->rating_count,
                ...$this->totals($rows->get($c->id, collect())),
                'active_students' => $active[$c->id] ?? 0,
            ])
            ->sortByDesc('enrollments')
            ->values()
            ->all();
    }

    /**
     * Where this course's students are now (not time-bound): enrolled, started, half-way, completed.
     *
     * @return array{enrolled: int, started: int, halfway: int, completed: int}
     */
    public function funnel(string $courseId): array
    {
        $row = DB::table('enrollments')
            ->where('course_id', $courseId)
            ->where('status', EnrollmentStatus::Active->value)
            ->selectRaw('count(*) as enrolled')
            ->selectRaw('count(*) filter (where progress_pct > 0) as started')
            ->selectRaw('count(*) filter (where progress_pct >= 50) as halfway')
            ->selectRaw('count(*) filter (where completed_at is not null) as completed')
            ->first();

        return [
            'enrolled' => (int) ($row->enrolled ?? 0),
            'started' => (int) ($row->started ?? 0),
            'halfway' => (int) ($row->halfway ?? 0),
            'completed' => (int) ($row->completed ?? 0),
        ];
    }

    /**
     * Distinct students who studied these courses between two days (inclusive): all courses together (key '') or
     * per course.
     *
     * @param  list<string>  $courseIds
     * @return array<string, int>
     */
    private function distinctActive(array $courseIds, CarbonImmutable $from, CarbonImmutable $to, bool $perCourse = false): array
    {
        if ($courseIds === []) {
            return [];
        }
        $query = DB::table('course_activity_days')
            ->whereIn('course_id', $courseIds)
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<=', $to->toDateString());

        return $perCourse
            ? $query->groupBy('course_id')->selectRaw('course_id, count(distinct user_id) as n')->pluck('n', 'course_id')->map(fn ($n) => (int) $n)->all()
            : ['' => (int) $query->distinct()->count('user_id')];
    }

    /** Percent change from the previous period; null when there was nothing before. */
    private static function changePct(int $now, int $before): ?int
    {
        return $before === 0 ? null : (int) round(($now - $before) * 100 / $before);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{enrollments: int, sales: int, completions: int, active_students: int, reviews: int, quiz_attempts: int, quiz_passes: int, revenue: list<array{amount_minor: int, currency: string}>}
     */
    private function totals(Collection $rows): array
    {
        $totals = [];
        foreach (self::COUNTS as $field) {
            $totals[$field] = (int) $rows->sum($field);
        }
        $revenue = [];
        foreach ($rows as $row) {
            /** @var array<string, int> $byCurrency */
            $byCurrency = json_decode((string) $row->revenue_minor, true) ?: [];
            foreach ($byCurrency as $currency => $amount) {
                $revenue[$currency] = ($revenue[$currency] ?? 0) + (int) $amount;
            }
        }
        ksort($revenue);
        $totals['revenue'] = array_map(
            fn (string $currency, int $amount): array => ['amount_minor' => $amount, 'currency' => $currency],
            array_keys($revenue),
            array_values($revenue),
        );

        return $totals;
    }
}
