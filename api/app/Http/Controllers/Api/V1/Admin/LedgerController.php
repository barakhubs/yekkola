<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Enums\EntryDirection;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\LedgerAccountResource;
use App\Http\Resources\Finance\LedgerTransactionResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Ledger explorer (PRD-09 finance views): accounts with their totals, and transactions with their entries.
 * Read-only — corrections are adjustments (POST /admin/ledger/adjustments).
 */
final class LedgerController extends Controller
{
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[type]', description: 'Account type, e.g. `professor_available`.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[currency]', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[owner_type]', description: '`platform` or `professor`.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[owner_id]', description: 'A professor profile id.', type: 'string')]
    public function accounts(Request $request): AnonymousResourceCollection
    {
        $accounts = QueryBuilder::for(LedgerAccount::query()->with('professor')
            ->withSum(['entries as debits_minor' => fn (Builder $q) => $q->where('direction', EntryDirection::Debit)], 'amount_minor')
            ->withSum(['entries as credits_minor' => fn (Builder $q) => $q->where('direction', EntryDirection::Credit)], 'amount_minor'))
            ->allowedFilters(AllowedFilter::exact('type'), AllowedFilter::exact('currency'), AllowedFilter::exact('owner_type'), AllowedFilter::exact('owner_id'))
            ->orderBy('owner_type')->orderBy('type')->orderBy('currency')->orderBy('id')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return LedgerAccountResource::collection($accounts);
    }

    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[type]', description: 'Transaction type, e.g. `sale`, `payout`, `adjustment`.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[reference_id]', description: 'The order, refund, payout… the transaction is about.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[account_id]', description: 'Transactions with an entry on this account.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[from]', type: 'string', format: 'date')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[to]', type: 'string', format: 'date')]
    public function transactions(Request $request): AnonymousResourceCollection
    {
        $transactions = QueryBuilder::for(LedgerTransaction::query()->with('entries.account.professor'))
            ->allowedFilters(
                AllowedFilter::exact('type'),
                AllowedFilter::exact('reference_id'),
                AllowedFilter::callback('account_id', fn (Builder $q, $id) => $q->whereHas('entries', fn (Builder $e) => $e->where('ledger_account_id', (string) $id))),
                AllowedFilter::callback('from', fn (Builder $q, $date) => $q->where('created_at', '>=', (string) $date)),
                AllowedFilter::callback('to', fn (Builder $q, $date) => $q->where('created_at', '<', now()->parse((string) $date)->addDay())),
            )
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return LedgerTransactionResource::collection($transactions);
    }
}
