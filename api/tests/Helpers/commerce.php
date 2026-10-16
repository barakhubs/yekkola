<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\EntryDirection;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Integrations\Payments\FakeGateway;
use App\Integrations\Payments\PaymentGateway;
use Illuminate\Testing\TestResponse;

/*
| Checkout and ledger helpers (PRD-05, PRD-08).
*/

function fakeGateway(): FakeGateway
{
    $gateway = app(PaymentGateway::class);
    assert($gateway instanceof FakeGateway);

    return $gateway;
}

/** Delivers a correctly signed gateway webhook for one of our payments. */
function sendPaymentWebhook(string $paymentId, ?string $eventId = null): TestResponse
{
    $request = fakeGateway()->webhookFor($paymentId, $eventId);

    return test()->call('POST', '/api/v1/webhooks/payments/fake', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_'.str_replace('-', '_', strtoupper(FakeGateway::WEBHOOK_SIGNATURE_HEADER)) => $request->headers->get(FakeGateway::WEBHOOK_SIGNATURE_HEADER),
    ], $request->getContent());
}

/** Signed balance (debits − credits) of a ledger account, from its entries. */
function ledgerBalance(LedgerAccountType $type, ?string $ownerId = null, string $currency = 'USD'): int
{
    $account = LedgerAccount::query()->where('type', $type)->where('owner_id', $ownerId)->where('currency', $currency)->first();
    if ($account === null) {
        return 0;
    }

    return (int) LedgerEntry::query()->where('ledger_account_id', $account->id)->get()
        ->sum(fn (LedgerEntry $e) => $e->direction === EntryDirection::Debit ? $e->amount_minor : -$e->amount_minor);
}

/** Every ledger transaction has debits equal to credits. */
function assertLedgerBalanced(): void
{
    foreach (LedgerTransaction::query()->with('entries')->get() as $transaction) {
        $net = $transaction->entries->sum(fn (LedgerEntry $e) => $e->direction === EntryDirection::Debit ? $e->amount_minor : -$e->amount_minor);
        expect($net)->toBe(0, "Transaction {$transaction->id} is unbalanced");
    }
}
