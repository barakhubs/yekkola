<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Activity;
use App\Domain\Platform\Settings\PayoutSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $settings = app(PayoutSettings::class);
    $settings->earnings_hold_days = 0;
    $settings->save();

    $this->professor = actingAsProfessor();
    $this->maths = publishedCourse($this->professor, 'Algèbre pour l’Exetat'); // 15.00 USD, 70% → 10.50
    $this->finance = actingAsStaff(Role::Admin, [Permission::FinanceAdjustments]);

    Sanctum::actingAs(User::factory()->create());
    $orderId = $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id]], ['Idempotency-Key' => 'o-'.Str::ulid()])->json('data.id');
    $paymentId = $this->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'airtel', 'msisdn' => '+243812345678'], ['Idempotency-Key' => 'p-'.$orderId])->json('data.id');
    sendPaymentWebhook($paymentId);
    $this->artisan('finance:release-earnings')->assertSuccessful();
});

function adjust(array $body, string $key): Illuminate\Testing\TestResponse
{
    Sanctum::actingAs(test()->finance);

    return test()->postJson('/api/v1/admin/ledger/adjustments', $body + [
        'professor_profile_id' => test()->professor->id,
        'currency' => 'USD',
        'label' => 'Bonus de lancement',
        'reason' => 'Accord commercial du 12 octobre',
    ], ['Idempotency-Key' => $key]);
}

it('shows the professor their balances, monthly earnings and statement, adjustments included', function () {
    adjust(['amount_minor' => 500, 'direction' => 'credit'], 'adj-1')->assertCreated();
    adjust(['amount_minor' => 500, 'direction' => 'credit'], 'adj-1')->assertCreated(); // replay: same answer, posted once

    Sanctum::actingAs($this->professor->user);
    $this->getJson('/api/v1/professor/earnings')->assertOk()
        ->assertJsonPath('data.balances.0', ['currency' => 'USD', 'held' => 0, 'available' => 1_550, 'in_payout' => 0, 'paid_out' => 0])
        ->assertJsonPath('data.monthly.0.amount_minor', 1_550)
        ->assertJsonPath('data.next_payout_on', fn (string $d) => $d > now()->toDateString());

    $lines = $this->getJson('/api/v1/professor/ledger')->assertOk()->json('data');
    expect(collect($lines)->pluck('type')->sort()->values()->all())->toBe(['adjustment', 'release', 'release', 'sale'])
        ->and(collect($lines)->firstWhere('type', 'adjustment'))->toMatchArray(['memo' => 'Bonus de lancement', 'amount' => ['amount_minor' => 500, 'currency' => 'USD']])
        ->and(collect($lines)->sum(fn (array $l) => $l['amount']['amount_minor']))->toBe(1_550);
    $this->getJson('/api/v1/professor/ledger?type=sale')->assertJsonCount(1, 'data');

    $csv = $this->get('/api/v1/professor/ledger.csv')->assertOk()->streamedContent();
    expect($csv)->toContain('Bonus de lancement')->toContain('adjustment');

    expect(LedgerTransaction::query()->where('type', LedgerTransactionType::Adjustment)->count())->toBe(1)
        ->and(Activity::query()->where('event', 'ledger.adjusted')->sole()->properties['reason'])->toBe('Accord commercial du 12 octobre');
    assertLedgerBalanced();
});

it('takes money back with a debit adjustment, finance-only', function () {
    adjust(['amount_minor' => 2_000, 'direction' => 'debit'], 'adj-2')->assertCreated();
    expect(-ledgerBalance(LedgerAccountType::ProfessorAvailable, $this->professor->id))->toBe(-950); // owes 9.50 now
    assertLedgerBalanced();

    Sanctum::actingAs(actingAsStaff(Role::Admin, [Permission::FinancePayouts]));
    $this->postJson('/api/v1/admin/ledger/adjustments', [
        'professor_profile_id' => $this->professor->id, 'currency' => 'USD', 'amount_minor' => 100,
        'direction' => 'credit', 'label' => 'Bonus', 'reason' => 'Test',
    ], ['Idempotency-Key' => 'adj-3'])->assertForbidden();
});

it('flags a transaction that does not balance', function () {
    $this->artisan('finance:check-ledger')->assertSuccessful();

    // Rows are append-only; a bad insert (a bug, a manual fix) is what the check is for.
    $account = LedgerAccount::query()->where('type', LedgerAccountType::GatewayClearing)->firstOrFail();
    $id = (string) Str::ulid();
    DB::table('ledger_transactions')->insert(['id' => $id, 'type' => 'adjustment', 'reference_type' => 'test', 'reference_id' => 't1', 'created_at' => now()]);
    DB::table('ledger_entries')->insert(['id' => (string) Str::ulid(), 'ledger_transaction_id' => $id, 'ledger_account_id' => $account->id, 'direction' => 'debit', 'amount_minor' => 100, 'currency' => 'USD', 'created_at' => now()]);

    $this->artisan('finance:check-ledger')->assertFailed()->expectsOutputToContain('unbalanced: 1');
});

it('posts an adjustment once even when its request key is no longer in the cache', function () {
    adjust(['amount_minor' => 300, 'direction' => 'credit'], 'adj-lost')->assertCreated();
    Cache::flush(); // evicted, expired, or forgotten after an error

    adjust(['amount_minor' => 300, 'direction' => 'credit'], 'adj-lost')->assertCreated();
    adjust(['amount_minor' => 300, 'direction' => 'credit'], 'adj-other')->assertCreated(); // a new request

    expect(LedgerTransaction::query()->where('type', LedgerTransactionType::Adjustment)->count())->toBe(2)
        ->and(Activity::query()->where('event', 'ledger.adjusted')->count())->toBe(2)
        ->and(-ledgerBalance(LedgerAccountType::ProfessorAvailable, $this->professor->id))->toBe(1_050 + 600);
    assertLedgerBalanced();
});

it('keeps a label that looks like a formula as text in the CSV', function () {
    adjust(['amount_minor' => 100, 'direction' => 'credit', 'label' => '=HYPERLINK("http://x.test")'], 'adj-csv')->assertCreated();
    Sanctum::actingAs($this->professor->user);

    expect($this->get('/api/v1/professor/ledger.csv')->streamedContent())->toContain("'=HYPERLINK");
});
