<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Finance\Console\BuildDuePayoutBatches;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Enums\PayoutStatus;
use App\Domain\Finance\Models\Payout;
use App\Domain\Platform\Settings\PayoutSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What a professor sees of their money (PRD-08 FR-09), all derived from the ledger: balances per currency,
 * earnings per month, and the statement lines.
 */
final class ProfessorEarnings
{
    /** Postings that change what the professor earned (moves between their own accounts and payouts don't). */
    public const EARNING_TYPES = [
        LedgerTransactionType::Sale,
        LedgerTransactionType::SaleReversal,
        LedgerTransactionType::Refund,
        LedgerTransactionType::RefundReversal,
        LedgerTransactionType::Adjustment,
    ];

    public function __construct(private readonly PayoutSettings $settings) {}

    /**
     * Per currency the professor has money in: held, available (part of it may be in a payout not confirmed yet:
     * `in_payout`), and paid out — what confirmed payouts took from available, net of reversals (so the four
     * reconcile with the ledger).
     *
     * @return list<array{currency: string, held: int, available: int, in_payout: int, paid_out: int}>
     */
    public function balances(string $professorProfileId): array
    {
        $rows = $this->entries($professorProfileId)
            ->groupBy('ledger_accounts.currency', 'ledger_accounts.type')
            ->selectRaw('ledger_accounts.currency as currency, ledger_accounts.type as type, '.self::SIGNED.' as balance')
            ->get();

        $paid = $this->entries($professorProfileId)
            ->whereIn('ledger_transactions.type', [LedgerTransactionType::Payout->value, LedgerTransactionType::PayoutFailed->value])
            ->groupBy('ledger_accounts.currency')
            ->selectRaw('ledger_accounts.currency as currency, -'.self::SIGNED.' as total')
            ->pluck('total', 'currency');
        $inFlight = Payout::query()->where('professor_profile_id', $professorProfileId)
            ->whereIn('status', [PayoutStatus::Pending, PayoutStatus::Processing])
            ->groupBy('currency')->selectRaw('currency, SUM(amount_minor) as total')->pluck('total', 'currency');

        $currencies = $rows->pluck('currency')->merge($inFlight->keys())->unique()->sort()->values();

        return $currencies->map(fn (string $currency) => [
            'currency' => $currency,
            'held' => (int) $rows->where('currency', $currency)->where('type', LedgerAccountType::ProfessorHeld->value)->sum('balance'),
            'available' => (int) $rows->where('currency', $currency)->where('type', LedgerAccountType::ProfessorAvailable->value)->sum('balance'),
            'in_payout' => (int) ($inFlight[$currency] ?? 0),
            'paid_out' => (int) ($paid[$currency] ?? 0),
        ])->all();
    }

    public function nextPayoutOn(): string
    {
        $day = now()->addDay()->startOfDay();
        while (! BuildDuePayoutBatches::isDue($this->settings->schedule, $day)) {
            $day = $day->addDay();
        }

        return $day->toDateString();
    }

    /**
     * Net earnings per month and currency over the last months, oldest first (for the earnings chart).
     *
     * @return list<array{month: string, currency: string, amount_minor: int}>
     */
    public function monthly(string $professorProfileId, int $months = 12): array
    {
        return $this->entries($professorProfileId)
            ->whereIn('ledger_transactions.type', array_map(fn (LedgerTransactionType $t) => $t->value, self::EARNING_TYPES))
            ->where('ledger_entries.created_at', '>=', now()->startOfMonth()->subMonths($months - 1))
            ->groupByRaw("to_char(ledger_entries.created_at, 'YYYY-MM'), ledger_accounts.currency")
            ->orderByRaw("to_char(ledger_entries.created_at, 'YYYY-MM')")
            ->selectRaw("to_char(ledger_entries.created_at, 'YYYY-MM') as month, ledger_accounts.currency as currency, ".self::SIGNED.' as amount')
            ->get()
            ->map(fn ($r) => ['month' => (string) $r->month, 'currency' => (string) $r->currency, 'amount_minor' => (int) $r->amount])
            ->values()->all();
    }

    /**
     * Statement lines, newest first: one per entry on the professor's accounts. Positive = owed to the professor.
     */
    public function statement(string $professorProfileId, ?string $type = null, ?string $from = null, ?string $to = null): Builder
    {
        return $this->entries($professorProfileId)
            ->when($type !== null, fn (Builder $q) => $q->where('ledger_transactions.type', $type))
            ->when($from !== null, fn (Builder $q) => $q->where('ledger_entries.created_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('ledger_entries.created_at', '<', now()->parse((string) $to)->addDay()))
            ->orderByDesc('ledger_entries.created_at')->orderByDesc('ledger_entries.id')
            ->select([
                'ledger_entries.id',
                'ledger_entries.created_at',
                'ledger_entries.amount_minor',
                'ledger_entries.direction',
                'ledger_accounts.currency',
                'ledger_accounts.type as account',
                'ledger_transactions.type',
                'ledger_transactions.reference_type',
                'ledger_transactions.reference_id',
                'ledger_transactions.memo',
            ]);
    }

    /** Signed sum: credits (owed to the professor) minus debits. */
    private const SIGNED = "SUM(CASE WHEN ledger_entries.direction = 'credit' THEN ledger_entries.amount_minor ELSE -ledger_entries.amount_minor END)";

    private function entries(string $professorProfileId): Builder
    {
        return DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.ledger_transaction_id')
            ->where('ledger_accounts.owner_type', Models\LedgerAccount::OWNER_PROFESSOR)
            ->where('ledger_accounts.owner_id', $professorProfileId)
            ->whereIn('ledger_accounts.type', [LedgerAccountType::ProfessorHeld->value, LedgerAccountType::ProfessorAvailable->value]);
    }
}
