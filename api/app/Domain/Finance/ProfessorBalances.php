<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Shared\Enums\Currency;
use Illuminate\Support\Facades\DB;

/**
 * Professor balances, derived from ledger entries (never stored, PRD-08 FR-01). Professor accounts are what the
 * platform owes: credits minus debits.
 */
final class ProfessorBalances
{
    /**
     * Available balance per professor profile id, for every professor with an available account in this currency.
     *
     * @return array<string, int>
     */
    public static function available(Currency $currency): array
    {
        return self::of(LedgerAccountType::ProfessorAvailable, $currency);
    }

    public static function availableFor(string $professorProfileId, Currency $currency): int
    {
        return self::of(LedgerAccountType::ProfessorAvailable, $currency, $professorProfileId)[$professorProfileId] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    private static function of(LedgerAccountType $type, Currency $currency, ?string $ownerId = null): array
    {
        $rows = DB::table('ledger_accounts')
            ->join('ledger_entries', 'ledger_entries.ledger_account_id', '=', 'ledger_accounts.id')
            ->where('ledger_accounts.type', $type->value)
            ->where('ledger_accounts.currency', $currency->value)
            ->whereNotNull('ledger_accounts.owner_id')
            ->when($ownerId !== null, fn ($q) => $q->where('ledger_accounts.owner_id', $ownerId))
            ->groupBy('ledger_accounts.owner_id')
            ->selectRaw("ledger_accounts.owner_id as owner_id, SUM(CASE WHEN ledger_entries.direction = 'credit' THEN ledger_entries.amount_minor ELSE -ledger_entries.amount_minor END) as balance")
            ->get();

        $balances = [];
        foreach ($rows as $row) {
            $balances[(string) $row->owner_id] = (int) $row->balance;
        }

        return $balances;
    }
}
