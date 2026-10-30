<?php

declare(strict_types=1);

namespace App\Domain\Finance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Daily ledger integrity check (PRD-08 FR-12): every transaction has entries, balances (debits = credits) in a
 * single currency, and every entry is in its account's currency. Balances are derived from entries, so they can't
 * drift on their own. Any finding is logged as critical (alerting picks it up) and fails the command.
 */
final class CheckLedger extends Command
{
    protected $signature = 'finance:check-ledger';

    protected $description = 'Check that every ledger transaction balances';

    public function handle(LoggerInterface $log): int
    {
        $signed = "SUM(CASE WHEN e.direction = 'debit' THEN e.amount_minor ELSE -e.amount_minor END)";

        $findings = [
            'unbalanced' => DB::table('ledger_transactions as t')->join('ledger_entries as e', 'e.ledger_transaction_id', '=', 't.id')
                ->groupBy('t.id')->havingRaw("{$signed} <> 0")->pluck('t.id')->all(),
            'mixed_currency' => DB::table('ledger_entries as e')->groupBy('e.ledger_transaction_id')
                ->havingRaw('COUNT(DISTINCT e.currency) > 1')->pluck('e.ledger_transaction_id')->all(),
            'no_entries' => DB::table('ledger_transactions as t')
                ->whereNotExists(fn ($q) => $q->from('ledger_entries as e')->whereColumn('e.ledger_transaction_id', 't.id'))
                ->pluck('t.id')->all(),
            'entry_currency' => DB::table('ledger_entries as e')->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
                ->whereColumn('e.currency', '!=', 'a.currency')->pluck('e.id')->all(),
        ];
        $findings = array_filter($findings);

        if ($findings === []) {
            $this->info('Ledger OK.');

            return self::SUCCESS;
        }

        $log->critical('Ledger integrity check failed', array_map(fn (array $ids) => array_slice($ids, 0, 50), $findings));
        foreach ($findings as $kind => $ids) {
            $this->error("{$kind}: ".count($ids));
        }

        return self::FAILURE;
    }
}
