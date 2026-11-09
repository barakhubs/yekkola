<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Learning\Enums\ReviewStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes one day of `course_daily_stats` for every course with activity that day (professor analytics),
 * from the source tables: running it again gives the same rows. Sales are order items that granted access that
 * day (`granted_at`, kept even if the enrollment is later revoked or re-granted); revenue is what students paid
 * for them (order item snapshots), per currency, before refunds — professor earnings stay on the ledger-backed
 * earnings page. Active students come from the append-only `course_activity_days`. Enrollments and completions
 * follow the enrollment row (a re-grant moves them to its own day); reviews hidden later drop out on the next
 * recompute of their day (today and yesterday hourly; older days with `--days`).
 */
final class RollupCourseStats
{
    public function handle(CarbonInterface $day): void
    {
        $from = $day->copy()->startOfDay();
        $to = $from->copy()->addDay();

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];
        $add = function (string $courseId, string $field, int|array $value) use (&$rows): void {
            $rows[$courseId] ??= [
                'enrollments' => 0, 'sales' => 0, 'revenue_minor' => [], 'completions' => 0,
                'active_students' => 0, 'reviews' => 0, 'quiz_attempts' => 0, 'quiz_passes' => 0,
            ];
            $rows[$courseId][$field] = $value;
        };

        $count = fn (string $table, string $column) => DB::table($table)
            ->where($column, '>=', $from)->where($column, '<', $to)
            ->groupBy('course_id')->selectRaw('course_id, count(*) as n')->pluck('n', 'course_id');

        foreach ($count('enrollments', 'enrolled_at') as $course => $n) {
            $add((string) $course, 'enrollments', (int) $n);
        }
        foreach ($count('enrollments', 'completed_at') as $course => $n) {
            $add((string) $course, 'completions', (int) $n);
        }

        // Sales: items that granted access that day (an item already owned was not sold; a reversed payment never sold).
        $sales = DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('i.granted_at', '>=', $from)->where('i.granted_at', '<', $to)
            ->where('o.status', '!=', OrderStatus::Reversed->value)
            ->groupBy('i.course_id', 'i.currency')
            ->selectRaw('i.course_id, i.currency, count(*) as n, sum(i.price_minor) as total')
            ->get();
        foreach ($sales->groupBy('course_id') as $course => $lines) {
            $add((string) $course, 'sales', (int) $lines->sum('n'));
            $add((string) $course, 'revenue_minor', $lines->mapWithKeys(fn ($l) => [$l->currency => (int) $l->total])->all());
        }

        $active = DB::table('course_activity_days')->where('date', $from->toDateString())
            ->groupBy('course_id')->selectRaw('course_id, count(*) as n')->pluck('n', 'course_id');
        foreach ($active as $course => $n) {
            $add((string) $course, 'active_students', (int) $n);
        }

        $reviews = DB::table('reviews')->where('status', ReviewStatus::Visible->value)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->groupBy('course_id')->selectRaw('course_id, count(*) as n')->pluck('n', 'course_id');
        foreach ($reviews as $course => $n) {
            $add((string) $course, 'reviews', (int) $n);
        }

        $quizzes = DB::table('quiz_attempts')->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $from)->where('submitted_at', '<', $to)
            ->groupBy('course_id')->selectRaw('course_id, count(*) as n, count(*) filter (where passed) as passed')->get();
        foreach ($quizzes as $q) {
            $add((string) $q->course_id, 'quiz_attempts', (int) $q->n);
            $add((string) $q->course_id, 'quiz_passes', (int) $q->passed);
        }

        DB::transaction(function () use ($rows, $from): void {
            $date = $from->toDateString();
            // A day recomputed to nothing (e.g. a review hidden since) must not keep its old row.
            DB::table('course_daily_stats')->where('date', $date)->whereNotIn('course_id', array_keys($rows))->delete();
            if ($rows === []) {
                return;
            }
            $now = now();
            DB::table('course_daily_stats')->upsert(
                array_map(fn (string $course, array $row): array => [
                    'course_id' => $course,
                    'date' => $date,
                    ...$row,
                    'revenue_minor' => json_encode((object) $row['revenue_minor'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], array_keys($rows), array_values($rows)),
                ['course_id', 'date'],
                ['enrollments', 'sales', 'revenue_minor', 'completions', 'active_students', 'reviews', 'quiz_attempts', 'quiz_passes', 'updated_at'],
            );
        });
    }
}
