<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\PayoutStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes one day of the admin dashboard figures (PRD-09 FR-01) from the source tables, so running it again —
 * or for a day already done — gives the same row. Money stays per currency (no conversion), in minor units.
 */
final class RollupPlatformStats
{
    public function handle(CarbonInterface $day): void
    {
        $from = $day->copy()->startOfDay();
        $to = $from->copy()->addDay();
        $between = fn (string $column) => fn ($q) => $q->where($column, '>=', $from)->where($column, '<', $to);

        $row = [
            'signups' => DB::table('users')->where($between('created_at'))->count(),
            'enrollments' => DB::table('enrollments')->where($between('enrolled_at'))->count(),
            'orders_created' => DB::table('orders')->where($between('created_at'))->count(),
            'orders_paid' => DB::table('orders')->where($between('paid_at'))->count(),
            'courses_published' => $this->coursesFirstPublished($from, $to),
            'gmv_minor' => $this->perCurrency(DB::table('orders')->where($between('paid_at')), 'SUM(total_minor)'),
            'net_revenue_minor' => $this->platformRevenue($from, $to),
            'refunds_minor' => $this->perCurrency(DB::table('refunds')->where('status', RefundStatus::Completed->value)->where($between('completed_at')), 'SUM(amount_minor)'),
            'payouts_minor' => $this->perCurrency(DB::table('payouts')->where('status', PayoutStatus::Paid->value)->where($between('paid_at')), 'SUM(amount_minor)'),
        ];

        DB::table('platform_daily_stats')->upsert(
            [['date' => $from->toDateString(), ...$this->encode($row), 'created_at' => now(), 'updated_at' => now()]],
            ['date'],
            [...array_keys($row), 'updated_at'],
        );
    }

    /** Courses whose first version was approved that day (later updates don't count again). */
    private function coursesFirstPublished(CarbonInterface $from, CarbonInterface $to): int
    {
        $published = [VersionState::Approved->value, VersionState::Superseded->value];

        return DB::table('course_versions as v')
            ->whereIn('v.state', $published)
            ->where('v.reviewed_at', '>=', $from)->where('v.reviewed_at', '<', $to)
            ->whereNotExists(fn ($q) => $q->from('course_versions as earlier')
                ->whereColumn('earlier.course_id', 'v.course_id')
                ->whereColumn('earlier.number', '<', 'v.number')
                ->whereIn('earlier.state', $published))
            ->distinct()->count('v.course_id');
    }

    /**
     * What the platform kept that day: credits minus debits on its revenue account (sales, refunds, reversals,
     * adjustments all net out here).
     *
     * @return array<string, int>
     */
    private function platformRevenue(CarbonInterface $from, CarbonInterface $to): array
    {
        return DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
            ->where('a.type', LedgerAccountType::PlatformRevenue->value)
            ->where('e.created_at', '>=', $from)->where('e.created_at', '<', $to)
            ->groupBy('a.currency')
            ->selectRaw("a.currency as currency, SUM(CASE WHEN e.direction = 'credit' THEN e.amount_minor ELSE -e.amount_minor END) as total")
            ->pluck('total', 'currency')
            ->map(fn ($v) => (int) $v)->all();
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array<string, int>
     */
    private function perCurrency($query, string $aggregate): array
    {
        return $query->groupBy('currency')->selectRaw("currency, {$aggregate} as total")
            ->pluck('total', 'currency')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function encode(array $row): array
    {
        return array_map(fn ($v) => is_array($v) ? json_encode((object) $v, JSON_THROW_ON_ERROR) : $v, $row);
    }
}
