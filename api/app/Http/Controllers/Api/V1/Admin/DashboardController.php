<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Finance\Enums\PayoutStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Settings\ModerationSettings;
use App\Domain\Professors\Enums\ApplicationStatus;
use App\Domain\Professors\Enums\KycSubmissionStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The back-office home (PRD-09 FR-01): the last 30 days against the 30 before (from the daily rollups), active
 * students, a daily series for the charts, and the queues needing attention. Each block shows only to staff
 * allowed to act on it: money to commerce or finance staff, each queue to those who work it.
 */
final class DashboardController extends Controller
{
    private const DAYS = 30;

    /** A payment still waiting this long is "stuck". */
    private const STUCK_AFTER_MINUTES = 10;

    /**
     * @return array{data: array{from: string, to: string, kpis: array{signups: array{value: int, previous: int}, enrollments: array{value: int, previous: int}, orders_paid: array{value: int, previous: int}, paid_conversion_pct: array{value: int|null, previous: int|null}, courses_published: array{value: int, previous: int}, active_students_7d: int, active_students_30d: int}, money: array{gmv: array<string, array{value: int, previous: int}>, net_revenue: array<string, array{value: int, previous: int}>}|null, series: list<array{date: string, signups: int, enrollments: int, orders_paid: int, gmv_minor: array<string, int>}>, queues: array{applications: array{count: int, overdue: int}|null, course_reviews: array{count: int, overdue: int}|null, kyc: int|null, refunds: int|null, stuck_payments: int|null, failed_payouts: int|null}}}
     */
    public function show(Request $request, ModerationSettings $moderation): array
    {
        /** @var User $staff */
        $staff = $request->user();
        $to = now()->startOfDay();
        $from = $to->copy()->subDays(self::DAYS - 1);
        $previousFrom = $from->copy()->subDays(self::DAYS);

        $rows = DB::table('platform_daily_stats')->where('date', '>=', $previousFrom->toDateString())->orderBy('date')->get();
        $current = $rows->filter(fn ($r) => (string) $r->date >= $from->toDateString());
        $previous = $rows->filter(fn ($r) => (string) $r->date < $from->toDateString());
        $sum = fn (Collection $set, string $key) => (int) $set->sum($key);
        $pair = fn (string $key) => ['value' => $sum($current, $key), 'previous' => $sum($previous, $key)];
        $conversion = fn (Collection $set) => $sum($set, 'orders_created') === 0 ? null
            : (int) round(100 * $sum($set, 'orders_paid') / $sum($set, 'orders_created'));
        $seesMoney = $staff->can(Permission::CommerceView->value) || $staff->can(Permission::FinanceView->value);

        return ['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'kpis' => [
                'signups' => $pair('signups'),
                'enrollments' => $pair('enrollments'),
                'orders_paid' => $pair('orders_paid'),
                'paid_conversion_pct' => ['value' => $conversion($current), 'previous' => $conversion($previous)],
                'courses_published' => $pair('courses_published'),
                'active_students_7d' => $this->activeStudents(7),
                'active_students_30d' => $this->activeStudents(30),
            ],
            'money' => $seesMoney ? [
                'gmv' => $this->moneyPairs($current, $previous, 'gmv_minor'),
                'net_revenue' => $this->moneyPairs($current, $previous, 'net_revenue_minor'),
            ] : null,
            'series' => $current->map(fn ($r) => [
                'date' => (string) $r->date,
                'signups' => (int) $r->signups,
                'enrollments' => (int) $r->enrollments,
                'orders_paid' => (int) $r->orders_paid,
                'gmv_minor' => $seesMoney ? $this->decode($r->gmv_minor) : [],
            ])->values()->all(),
            'queues' => $this->queues($staff, $moderation),
        ]];
    }

    /** Students with learning activity in the last N days (live: distinct students can't be summed by day). */
    private function activeStudents(int $days): int
    {
        return DB::table('enrollments')->where('last_activity_at', '>=', now()->subDays($days))->distinct()->count('user_id');
    }

    /**
     * @return array{applications: array{count: int, overdue: int}|null, course_reviews: array{count: int, overdue: int}|null, kyc: int|null, refunds: int|null, stuck_payments: int|null, failed_payouts: int|null}
     */
    private function queues(User $staff, ModerationSettings $moderation): array
    {
        $review = $staff->can(Permission::ModerationReview->value);
        $applications = DB::table('professor_applications')->whereIn('status', [ApplicationStatus::Submitted->value, ApplicationStatus::UnderReview->value]);
        $versions = DB::table('course_versions')->where('state', VersionState::InReview->value);

        return [
            'applications' => $review ? [
                'count' => (clone $applications)->count(),
                'overdue' => (clone $applications)->where('submitted_at', '<', now()->subHours($moderation->application_review_sla_hours))->count(),
            ] : null,
            'course_reviews' => $review ? [
                'count' => (clone $versions)->count(),
                'overdue' => (clone $versions)->where('submitted_at', '<', now()->subHours($moderation->course_review_sla_hours))->count(),
            ] : null,
            'kyc' => $staff->can(Permission::KycReview->value)
                ? DB::table('kyc_submissions')->where('status', KycSubmissionStatus::Pending->value)->count() : null,
            'refunds' => $staff->can(Permission::FinanceRefunds->value)
                ? DB::table('refunds')->whereIn('status', [RefundStatus::Requested->value, RefundStatus::Failed->value])->count() : null,
            'stuck_payments' => $staff->can(Permission::CommerceView->value)
                ? DB::table('payments')->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Pending->value])
                    ->where('created_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))->count() : null,
            'failed_payouts' => $staff->can(Permission::FinancePayouts->value)
                ? DB::table('payouts')->where('status', PayoutStatus::Failed->value)->where('updated_at', '>=', now()->subDays(self::DAYS))->count() : null,
        ];
    }

    /**
     * @param  Collection<int, stdClass>  $current
     * @param  Collection<int, stdClass>  $previous
     * @return array<string, array{value: int, previous: int}>
     */
    private function moneyPairs(Collection $current, Collection $previous, string $column): array
    {
        $totals = fn (Collection $set) => $set->reduce(function (array $carry, stdClass $row) use ($column): array {
            foreach ($this->decode($row->{$column}) as $currency => $minor) {
                $carry[$currency] = ($carry[$currency] ?? 0) + $minor;
            }

            return $carry;
        }, []);
        $now = $totals($current);
        $before = $totals($previous);

        $pairs = [];
        foreach (array_unique([...array_keys($now), ...array_keys($before)]) as $currency) {
            $pairs[(string) $currency] = ['value' => $now[$currency] ?? 0, 'previous' => $before[$currency] ?? 0];
        }
        ksort($pairs);

        return $pairs;
    }

    /**
     * @return array<string, int>
     */
    private function decode(mixed $json): array
    {
        $values = is_string($json) ? json_decode($json, true) : [];

        return is_array($values) ? array_map(fn ($v) => (int) $v, $values) : [];
    }
}
