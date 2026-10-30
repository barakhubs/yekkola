<?php

declare(strict_types=1);

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Commerce\Models\Order;
use App\Domain\Finance\Actions\PayoutLifecycle;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\PayoutStatus;
use App\Domain\Finance\Jobs\ExecutePayout;
use App\Domain\Finance\Models\Payout;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Settings\PayoutSettings;
use App\Domain\Professors\Enums\KycStatus;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Integrations\Payments\Scenario;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $settings = app(PayoutSettings::class);
    $settings->earnings_hold_days = 0;
    $settings->save();

    $this->professor = payableProfessor(actingAsProfessor());
    $this->maths = publishedCourse($this->professor, 'Algèbre pour l’Exetat'); // 15.00 USD, 70% → 10.50
    $this->finance = actingAsStaff(Role::Admin, [Permission::FinancePayouts]);
    $this->student = User::factory()->create(['name' => 'Grâce Mbuyi']);
});

function payableProfessor(ProfessorProfile $profile, string $msisdn = '+243812345678'): ProfessorProfile
{
    $profile->forceFill([
        'kyc_status' => KycStatus::Verified,
        'payout_rail' => MobileMoneyRail::Airtel,
        'payout_msisdn' => $msisdn,
        'payout_name' => $profile->display_name,
    ])->save();

    return $profile;
}

/** The student buys these courses; earnings are released at once (hold 0 days). */
function soldAndReleased(array $courseIds): Order
{
    Sanctum::actingAs(test()->student);
    $orderId = test()->postJson('/api/v1/orders', ['course_ids' => $courseIds], ['Idempotency-Key' => 'order-'.Str::ulid()])->json('data.id');
    $paymentId = test()->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'airtel', 'msisdn' => '+243812345678'], ['Idempotency-Key' => 'pay-'.$orderId])->json('data.id');
    sendPaymentWebhook($paymentId);
    test()->artisan('finance:release-earnings')->assertSuccessful();

    return Order::query()->findOrFail($orderId);
}

function buildBatch(string $currency = 'USD'): Illuminate\Testing\TestResponse
{
    Sanctum::actingAs(test()->finance);

    return test()->postJson('/api/v1/admin/payout-batches', ['currency' => $currency]);
}

function approveBatch(string $batchId): Illuminate\Testing\TestResponse
{
    Sanctum::actingAs(test()->finance);

    return test()->postJson("/api/v1/admin/payout-batches/{$batchId}/approve", [], ['Idempotency-Key' => 'approve-'.$batchId]);
}

function available(ProfessorProfile $professor, string $currency = 'USD'): int
{
    return -ledgerBalance(LedgerAccountType::ProfessorAvailable, $professor->id, $currency); // what we owe
}

it('pays every payable professor their available balance, once', function () {
    $unverified = actingAsProfessor('Jean Ilunga');
    $unverified->forceFill(['payout_rail' => MobileMoneyRail::Orange, 'payout_msisdn' => '+243899999999'])->save(); // KYC not done
    $other = publishedCourse($unverified, 'Chimie');
    soldAndReleased([$this->maths->id, $other->id]);
    expect(available($this->professor))->toBe(1_050);

    $batch = buildBatch()->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonCount(1, 'data.payouts')
        ->assertJsonPath('data.payouts.0.amount.amount_minor', 1_050)
        ->assertJsonPath('data.payouts.0.msisdn', fn (string $m) => ! str_contains($m, '345678'))
        ->json('data');
    buildBatch()->assertConflict()->assertJsonPath('code', 'payout.batch_open');

    approveBatch($batch['id'])->assertOk()->assertJsonPath('data.status', 'processing');
    $payout = Payout::query()->sole();
    expect($payout->status)->toBe(PayoutStatus::Processing);

    sendPaymentWebhook($payout->reference, 'evt-p1');
    sendPaymentWebhook($payout->reference, 'evt-p1'); // duplicate event

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and(available($this->professor))->toBe(0)
        ->and(available($unverified))->toBe(1_050) // stays owed until they can be paid
        ->and(ledgerBalance(LedgerAccountType::GatewayClearing))->toBe(3_000 - 1_050);
    $this->getJson("/api/v1/admin/payout-batches/{$batch['id']}")->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.paid_total.amount_minor', 1_050);
    assertLedgerBalanced();

    buildBatch()->assertUnprocessable()->assertJsonPath('code', 'payout.nothing_to_pay');
});

it('leaves the money available when a payout fails, for the next batch', function () {
    soldAndReleased([$this->maths->id]);
    $batch = buildBatch()->json('data.id');
    fakeGateway()->queue(Scenario::Fail);
    approveBatch($batch);
    $payout = Payout::query()->sole();
    sendPaymentWebhook($payout->reference, 'evt-fail');

    expect($payout->refresh()->status)->toBe(PayoutStatus::Failed)
        ->and(available($this->professor))->toBe(1_050);
    $this->getJson("/api/v1/admin/payout-batches/{$batch}")->assertJsonPath('data.status', 'partially_failed');

    // Not paid again while that failure may still turn into a late success; then in the next run.
    buildBatch()->assertUnprocessable()->assertJsonPath('code', 'payout.nothing_to_pay');
    $this->travel(PayoutLifecycle::UNCERTAIN_HOURS + 1)->hours();
    buildBatch()->assertCreated()->assertJsonPath('data.payouts.0.amount.amount_minor', 1_050);
    assertLedgerBalanced();
});

it('sends lines an approved batch left pending (lost job), so the batch closes', function () {
    soldAndReleased([$this->maths->id]);
    $batch = buildBatch()->json('data.id');
    Bus::fake([ExecutePayout::class]);
    approveBatch($batch)->assertOk();
    $payout = Payout::query()->sole();
    expect($payout->status)->toBe(PayoutStatus::Pending);

    $this->travel(11)->minutes();
    $this->artisan('finance:sync-payouts')->assertSuccessful();
    sendPaymentWebhook($payout->reference, 'evt-late');

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid)->and(available($this->professor))->toBe(0);
    $this->getJson("/api/v1/admin/payout-batches/{$batch}")->assertJsonPath('data.status', 'completed');
    assertLedgerBalanced();
});

it('makes the money available again when the operator reverses a payout', function () {
    soldAndReleased([$this->maths->id]);
    $batch = buildBatch()->json('data.id');
    fakeGateway()->queue(Scenario::Reversal);
    approveBatch($batch);
    $payout = Payout::query()->sole();

    sendPaymentWebhook($payout->reference, 'evt-r1'); // paid
    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid)->and(available($this->professor))->toBe(0);
    sendPaymentWebhook($payout->reference, 'evt-r2'); // then reversed

    expect($payout->refresh()->status)->toBe(PayoutStatus::Failed)
        ->and($payout->failure_code)->toBe('reversed')
        ->and(available($this->professor))->toBe(1_050);
    assertLedgerBalanced();
});

it('sends nothing for a line left out, or for a professor whose payout number changed since', function () {
    $second = payableProfessor(actingAsProfessor('Jean Ilunga'), '+243899999999');
    soldAndReleased([$this->maths->id, publishedCourse($second, 'Chimie')->id]);
    $batch = buildBatch()->assertJsonCount(2, 'data.payouts')->json('data');
    $first = Payout::query()->where('professor_profile_id', $this->professor->id)->sole();

    $this->postJson("/api/v1/admin/payouts/{$first->id}/exclude", ['note' => 'Contrôle KYC à refaire'])->assertOk()
        ->assertJsonPath('data.status', 'excluded');
    $second->forceFill(['payout_msisdn' => '+243811111111'])->save();

    approveBatch($batch['id'])->assertOk();

    expect($first->refresh()->status)->toBe(PayoutStatus::Excluded)
        ->and(Payout::query()->where('professor_profile_id', $second->id)->sole()->failure_code)->toBe('payout_method_changed')
        ->and(available($this->professor))->toBe(1_050)
        ->and(available($second))->toBe(1_050);
    $this->getJson("/api/v1/admin/payout-batches/{$batch['id']}")->assertJsonPath('data.status', 'partially_failed');
    $this->postJson("/api/v1/admin/payouts/{$first->id}/exclude", ['note' => 'Trop tard'])->assertConflict();
    assertLedgerBalanced();
});

it('never lets staff approve a batch that pays themselves', function () {
    soldAndReleased([$this->maths->id]);
    $batch = buildBatch()->json('data.id');
    $this->professor->user->assignRole(Role::Admin->value); // a professor who is also on staff
    $this->professor->user->givePermissionTo(Permission::FinancePayouts->value);
    Sanctum::actingAs($this->professor->user);

    $this->postJson("/api/v1/admin/payout-batches/{$batch}/approve", [], ['Idempotency-Key' => 'own'])->assertForbidden()
        ->assertJsonPath('code', 'payout.own_payout');
});

it('pays CDF in whole francs and keeps the centimes for the next batch', function () {
    $settings = app(PayoutSettings::class);
    $settings->minimum_minor = ['USD' => 1_000, 'CDF' => 10_000];
    $settings->save();
    $course = publishedCourse($this->professor, 'Physique', ['price_minor' => 30_100, 'currency' => 'CDF']); // 70% → 21,070 centimes
    soldAndReleased([$course->id]);

    buildBatch('CDF')->assertCreated()->assertJsonPath('data.payouts.0.amount.amount_minor', 21_000);

    expect(available($this->professor, 'CDF'))->toBe(21_070);
});
