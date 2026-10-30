<?php

declare(strict_types=1);

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Finance\Actions\PostLedgerTransaction;
use App\Domain\Finance\EntryLine;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Settings\PayoutSettings;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Integrations\Payments\Scenario;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor();
    $this->maths = publishedCourse($this->professor, 'Algèbre pour l’Exetat'); // 15.00 USD, 70% → 10.50
    $this->finance = actingAsStaff(Role::Admin, [Permission::FinanceRefunds]);
    $this->student = User::factory()->create(['name' => 'Grâce Mbuyi']);
    Sanctum::actingAs($this->student);
});

/** Paid order for the signed-in student; the payer's last digits pick the fake gateway's scenario. */
function paidOrder(array $courseIds, string $payer = '+243812345678'): Order
{
    $orderId = test()->postJson('/api/v1/orders', ['course_ids' => $courseIds], ['Idempotency-Key' => 'order-'.Str::ulid()])->json('data.id');
    $paymentId = test()->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'airtel', 'msisdn' => $payer], ['Idempotency-Key' => 'pay-'.$orderId])->json('data.id');
    sendPaymentWebhook($paymentId);

    return Order::query()->with('items')->findOrFail($orderId);
}

function holdDays(int $days): void
{
    $settings = app(PayoutSettings::class);
    $settings->earnings_hold_days = $days;
    $settings->save();
}

function releases(): int
{
    return LedgerTransaction::query()->where('type', LedgerTransactionType::Release)->count();
}

it('releases earnings once the hold period has passed, once', function () {
    paidOrder([$this->maths->id]);
    $held = fn () => ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id);
    $available = fn () => ledgerBalance(LedgerAccountType::ProfessorAvailable, $this->professor->id);

    $this->artisan('finance:release-earnings')->assertSuccessful();
    expect($held())->toBe(-1_050)->and($available())->toBe(0); // still in the hold period

    $this->travel(app(PayoutSettings::class)->earnings_hold_days)->days();
    $this->artisan('finance:release-earnings')->assertSuccessful();
    $this->artisan('finance:release-earnings')->assertSuccessful(); // a second run changes nothing

    expect($held())->toBe(0)->and($available())->toBe(-1_050)->and(releases())->toBe(1);
    assertLedgerBalanced();
});

it('takes a refund after release back from available', function () {
    holdDays(0);
    $order = paidOrder([$this->maths->id]);
    $this->artisan('finance:release-earnings')->assertSuccessful();

    $id = $this->postJson("/api/v1/orders/{$order->id}/refund-requests", [
        'order_item_id' => $order->items->sole()->id,
        'reason' => 'Le cours ne correspond pas à mon niveau.',
    ])->assertCreated()->json('data.id');
    fakeGateway()->queue(Scenario::Succeed);
    Sanctum::actingAs($this->finance);
    $this->postJson("/api/v1/admin/refunds/{$id}/approve")->assertOk();

    expect(ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id))->toBe(0)
        ->and(ledgerBalance(LedgerAccountType::ProfessorAvailable, $this->professor->id))->toBe(0)
        ->and(Refund::query()->findOrFail($id)->status->value)->toBe('processing');
    assertLedgerBalanced();
});

it('takes a reversal after release back from available, which may go negative', function () {
    holdDays(0);
    $order = paidOrder([$this->maths->id], '+243812340004'); // the operator reverses it later
    $this->artisan('finance:release-earnings')->assertSuccessful();

    // Paid out meanwhile: a later reversal leaves the professor owing it (netted against future earnings).
    expect(releases())->toBe(1);
    $share = Money::of(1_050, Currency::USD);
    app(PostLedgerTransaction::class)->handle(LedgerTransactionType::Payout, 'test', 'p1', [
        EntryLine::debit(LedgerAccountType::ProfessorAvailable, $share, $this->professor->id),
        EntryLine::credit(LedgerAccountType::ProfessorPaidOut, $share, $this->professor->id),
    ]);

    sendPaymentWebhook($order->paid_payment_id, 'evt-reverse');

    expect(ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id))->toBe(0)
        ->and(ledgerBalance(LedgerAccountType::ProfessorAvailable, $this->professor->id))->toBe(1_050); // owes 10.50
    assertLedgerBalanced();
});

it('does not release an item whose refund was already approved', function () {
    $order = paidOrder([$this->maths->id]);
    $id = $this->postJson("/api/v1/orders/{$order->id}/refund-requests", [
        'order_item_id' => $order->items->sole()->id,
        'reason' => 'Le cours ne correspond pas à mon niveau.',
    ])->json('data.id');
    fakeGateway()->queue(Scenario::Succeed);
    Sanctum::actingAs($this->finance);
    $this->postJson("/api/v1/admin/refunds/{$id}/approve")->assertOk();

    $this->travel(app(PayoutSettings::class)->earnings_hold_days)->days();
    $this->artisan('finance:release-earnings')->assertSuccessful();

    expect(releases())->toBe(0)
        ->and(ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id))->toBe(0)
        ->and(ledgerBalance(LedgerAccountType::ProfessorAvailable, $this->professor->id))->toBe(0);
    assertLedgerBalanced();
});

it('never releases a course the order did not sell because the student already owned it', function () {
    $physics = publishedCourse($this->professor, 'Physique de base', ['price_minor' => 2_000]); // 70% → 14.00
    $orderId = $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id, $physics->id]], ['Idempotency-Key' => 'o-both'])->json('data.id');
    // Maths is granted another way before the payment lands: this order then sells only physics.
    Enrollment::query()->create(['user_id' => $this->student->id, 'course_id' => $this->maths->id, 'source' => 'admin', 'status' => 'active', 'enrolled_at' => now()]);
    $paymentId = $this->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'airtel', 'msisdn' => '+243812345678'], ['Idempotency-Key' => 'pay-both'])->json('data.id');
    sendPaymentWebhook($paymentId);
    expect(ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id))->toBe(-1_400); // physics only

    $this->travel(app(PayoutSettings::class)->earnings_hold_days)->days();
    $this->artisan('finance:release-earnings')->assertSuccessful();

    expect(releases())->toBe(1)
        ->and(ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id))->toBe(0)
        ->and(ledgerBalance(LedgerAccountType::ProfessorAvailable, $this->professor->id))->toBe(-1_400);
    assertLedgerBalanced();
});
