<?php

declare(strict_types=1);

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\EnrollmentStatus;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Models\Activity;
use App\Integrations\Payments\Scenario;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor();
    $this->maths = publishedCourse($this->professor, 'Algèbre pour l’Exetat'); // 15.00 USD
    $this->physics = publishedCourse($this->professor, 'Physique de base', ['price_minor' => 2_000]);
    $this->finance = actingAsStaff(Role::Admin, [Permission::FinanceRefunds]);

    $this->student = User::factory()->create(['name' => 'Grâce Mbuyi']);
    Sanctum::actingAs($this->student);
    $this->order = boughtOrder([$this->maths->id, $this->physics->id]);
});

/** Paid order for the signed-in student. */
function boughtOrder(array $courseIds, string $payer = '+243812345678'): Order
{
    $orderId = test()->postJson('/api/v1/orders', ['course_ids' => $courseIds], ['Idempotency-Key' => 'order-'.implode('-', $courseIds)])->json('data.id');
    $paymentId = test()->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'airtel', 'msisdn' => $payer], ['Idempotency-Key' => 'pay-'.$orderId])->json('data.id');
    sendPaymentWebhook($paymentId);

    return Order::query()->with('items')->findOrFail($orderId);
}

function askRefund(Order $order, string $courseId): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/orders/{$order->id}/refund-requests", [
        'order_item_id' => $order->items->firstWhere('course_id', $courseId)->id,
        'reason' => 'Le cours ne correspond pas à mon niveau.',
    ]);
}

function confirmRefundPayout(Refund $refund): void
{
    sendPaymentWebhook($refund->refresh()->reference(), 'evt-'.$refund->reference());
}

it('gives receipts as JSON and PDF, in the reader\'s language', function () {
    $this->getJson("/api/v1/orders/{$this->order->id}/receipt", ['Accept-Language' => 'fr'])
        ->assertOk()
        ->assertJsonPath('data.number', $this->order->number)
        ->assertJsonPath('data.student', 'Grâce Mbuyi')
        ->assertJsonPath('data.total.formatted', "35,00\u{a0}\$US") // French: non-breaking space
        ->assertJsonPath('data.payment.rail', 'airtel');

    $pdf = $this->get("/api/v1/orders/{$this->order->id}/receipt.pdf", ['Accept-Language' => 'en'])->assertOk();
    expect($pdf->headers->get('content-type'))->toBe('application/pdf')
        ->and($pdf->headers->get('content-disposition'))->toContain('receipt-'.$this->order->number.'.pdf');

    $unpaid = $this->postJson('/api/v1/orders', ['course_ids' => [publishedCourse($this->professor, 'Chimie')->id]], ['Idempotency-Key' => 'o-x'])->json('data.id');
    $this->getJson("/api/v1/orders/{$unpaid}/receipt")->assertNotFound();

    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/v1/orders/{$this->order->id}/receipt")->assertNotFound();
});

it('refunds a course: access removed, sale taken back, money sent to the payer', function () {
    $refund = askRefund($this->order, $this->maths->id)->assertCreated()->assertJsonPath('data.status', 'requested')->json('data');
    askRefund($this->order, $this->maths->id)->assertConflict()->assertJsonPath('code', 'refund.already_requested');

    Sanctum::actingAs($this->finance);
    $this->getJson("/api/v1/admin/refunds/{$refund['id']}")->assertOk()
        ->assertJsonPath('data.policy.days_since_payment', 0)
        ->assertJsonPath('data.policy.max_progress_pct', 20);
    $this->postJson("/api/v1/admin/refunds/{$refund['id']}/approve")->assertOk()->assertJsonPath('data.status', 'processing');

    $model = Refund::query()->findOrFail($refund['id']);
    expect(Enrollment::query()->where('course_id', $this->maths->id)->sole()->status)->toBe(EnrollmentStatus::Revoked)
        ->and(Enrollment::query()->where('course_id', $this->physics->id)->sole()->isActive())->toBeTrue()
        ->and($this->order->refresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and(ledgerBalance(LedgerAccountType::RefundsPayable))->toBe(-1_500)
        ->and(Activity::query()->where('event', 'refund.approved')->sole()->causer_id)->toBe($this->finance->id);

    confirmRefundPayout($model);
    confirmRefundPayout($model); // duplicate webhook

    expect($model->refresh()->status)->toBe(RefundStatus::Completed)
        ->and(ledgerBalance(LedgerAccountType::RefundsPayable))->toBe(0)
        ->and(ledgerBalance(LedgerAccountType::GatewayClearing))->toBe(2_000) // only the physics course stays collected
        ->and(ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id))->toBe(-1_400) // 70% of 20.00
        ->and(ledgerBalance(LedgerAccountType::PlatformRevenue))->toBe(-600);
    assertLedgerBalanced();

    Sanctum::actingAs($this->student);
    $this->getJson("/api/v1/orders/{$this->order->id}/receipt")->assertJsonPath('data.refunded.amount_minor', 1_500);
    $this->getJson('/api/v1/me/refunds')->assertJsonPath('data.0.status', 'completed');
});

it('applies the refund window and progress limit from the settings', function () {
    Enrollment::query()->where('course_id', $this->physics->id)->update(['progress_pct' => 35]);
    askRefund($this->order, $this->physics->id)->assertUnprocessable()->assertJsonPath('code', 'refund.progress_too_high');

    $this->travel(8)->days();
    askRefund($this->order, $this->maths->id)->assertUnprocessable()->assertJsonPath('code', 'refund.window_closed');
});

it('rejects with a note, after which the student can ask again', function () {
    $id = askRefund($this->order, $this->maths->id)->json('data.id');

    Sanctum::actingAs($this->finance);
    $this->postJson("/api/v1/admin/refunds/{$id}/reject")->assertUnprocessable();
    $this->postJson("/api/v1/admin/refunds/{$id}/reject", ['note' => 'Cours déjà terminé.'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson("/api/v1/admin/refunds/{$id}/approve")->assertConflict()->assertJsonPath('code', 'refund.invalid_transition');

    Sanctum::actingAs($this->student);
    askRefund($this->order, $this->maths->id)->assertCreated();
    expect(Enrollment::query()->where('course_id', $this->maths->id)->sole()->isActive())->toBeTrue();
});

it('retries a failed payout under a new reference, and reconciliation finishes it', function () {
    $id = askRefund($this->order, $this->maths->id)->json('data.id');
    fakeGateway()->queue(Scenario::Fail);

    Sanctum::actingAs($this->finance);
    $this->postJson("/api/v1/admin/refunds/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'failed');
    $this->postJson("/api/v1/admin/refunds/{$id}/retry", ['note' => 'Numéro corrigé'])->assertOk()->assertJsonPath('data.status', 'processing');

    $refund = Refund::query()->findOrFail($id);
    expect($refund->attempt)->toBe(2)->and($refund->reference())->toEndWith('-2');

    $this->travel(3)->minutes();
    $this->artisan('commerce:reconcile')->assertSuccessful();

    expect($refund->refresh()->status)->toBe(RefundStatus::Completed);
    assertLedgerBalanced();
});

it('sends back money a payment collected but never applied', function () {
    Sanctum::actingAs($this->student);
    $mismatch = publishedCourse($this->professor, 'Biologie');
    $orderId = $this->postJson('/api/v1/orders', ['course_ids' => [$mismatch->id]], ['Idempotency-Key' => 'o-m'])->json('data.id');
    $paymentId = $this->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'orange', 'msisdn' => '+243812340008'], ['Idempotency-Key' => 'p-m'])->json('data.id');
    sendPaymentWebhook($paymentId);
    expect(ledgerBalance(LedgerAccountType::RefundsPayable))->toBe(-1_499);

    Sanctum::actingAs($this->finance);
    $refund = $this->postJson("/api/v1/admin/payments/{$paymentId}/refund")->assertCreated()->assertJsonPath('data.amount.amount_minor', 1_499)->json('data');
    $this->postJson("/api/v1/admin/payments/{$paymentId}/refund")->assertConflict();
    $this->postJson('/api/v1/admin/payments/'.Payment::query()->where('order_id', $this->order->id)->value('id').'/refund')
        ->assertUnprocessable()->assertJsonPath('code', 'refund.nothing_unapplied');

    confirmRefundPayout(Refund::query()->findOrFail($refund['id']));

    // This payer number (…0008) also makes the fake gateway pay out one unit less: the ledger posts what was
    // really sent, keeps the unit still owed, and flags it.
    expect(Refund::query()->findOrFail($refund['id'])->flag)->toBe('amount_mismatch')
        ->and(ledgerBalance(LedgerAccountType::RefundsPayable))->toBe(-1)
        ->and(ledgerBalance(LedgerAccountType::GatewayClearing))->toBe(3_500 + 1);
    assertLedgerBalanced();
});

it('keeps the refund queue to staff with the finance permission', function () {
    actingAsStaff(Role::Admin);
    $this->getJson('/api/v1/admin/refunds')->assertForbidden();
});

it('calls off a refund request when the operator reverses the payment first', function () {
    Sanctum::actingAs($this->student);
    $reversible = boughtOrder([publishedCourse($this->professor, 'Géographie')->id], '+243812340004'); // reversed at check 2
    $id = askRefund($reversible, $reversible->items->sole()->course_id)->json('data.id');

    sendPaymentWebhook($reversible->paid_payment_id, 'evt-reverse');

    expect(Refund::query()->findOrFail($id)->status)->toBe(RefundStatus::Rejected)
        ->and($reversible->refresh()->status)->toBe(OrderStatus::Reversed);

    Sanctum::actingAs($this->finance);
    $this->postJson("/api/v1/admin/refunds/{$id}/approve")->assertConflict();
    assertLedgerBalanced();
});

it('records the payer\'s debt when a payment is reversed after a refund was paid out', function () {
    Sanctum::actingAs($this->student);
    $order = boughtOrder([$geo = publishedCourse($this->professor, 'Géographie')->id, $hist = publishedCourse($this->professor, 'Histoire', ['price_minor' => 2_000])->id], '+243812340004');
    $id = askRefund($order, $geo)->json('data.id');

    fakeGateway()->queue(Scenario::Succeed);
    Sanctum::actingAs($this->finance);
    $this->postJson("/api/v1/admin/refunds/{$id}/approve")->assertOk();
    confirmRefundPayout(Refund::query()->findOrFail($id));
    expect($order->refresh()->status)->toBe(OrderStatus::PartiallyRefunded);

    sendPaymentWebhook($order->paid_payment_id, 'evt-reverse'); // the operator takes the whole payment back

    expect($order->refresh()->status)->toBe(OrderStatus::Reversed)
        ->and(Enrollment::query()->where('course_id', $hist)->sole()->status)->toBe(EnrollmentStatus::Revoked)
        ->and(Refund::query()->findOrFail($id)->flag)->toBe('paid_after_reversal')
        ->and(ledgerBalance(LedgerAccountType::PayerReceivable))->toBe(1_500) // paid twice for that course
        ->and(ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id))->toBe(-(1_050 + 1_400)) // only the first order's earnings remain
        ->and(ledgerBalance(LedgerAccountType::RefundsPayable))->toBe(0);
    assertLedgerBalanced();
});

it('cancels an approved refund whose payout failed when the payment is reversed', function () {
    Sanctum::actingAs($this->student);
    $order = boughtOrder([$geo = publishedCourse($this->professor, 'Géographie')->id], '+243812340004');
    $id = askRefund($order, $geo)->json('data.id');

    fakeGateway()->queue(Scenario::Fail);
    Sanctum::actingAs($this->finance);
    $this->postJson("/api/v1/admin/refunds/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'failed');

    sendPaymentWebhook($order->paid_payment_id, 'evt-reverse');

    expect(Refund::query()->findOrFail($id)->status)->toBe(RefundStatus::Cancelled);
    $this->postJson("/api/v1/admin/refunds/{$id}/retry", ['note' => 'Réessayer'])->assertConflict();
    assertLedgerBalanced();
});

it('records an earlier payout attempt that went through after all', function () {
    $id = askRefund($this->order, $this->maths->id)->json('data.id');
    Sanctum::actingAs($this->finance);
    $this->postJson("/api/v1/admin/refunds/{$id}/approve")->assertOk();
    $refund = Refund::query()->findOrFail($id);
    confirmRefundPayout($refund); // attempt 1 completes

    // Pretend attempt 1 had been written off and a second attempt paid: attempt 1 reporting success is a double payout.
    $refund->forceFill(['attempt' => 2])->save();
    app(App\Domain\Commerce\Actions\RefundLifecycle::class)->syncReference($refund->refresh(), 'R-'.$refund->id.'-1');

    expect($refund->refresh()->flag)->toBe('old_attempt_paid')
        ->and(ledgerBalance(LedgerAccountType::PayerReceivable))->toBe(1_500);
    assertLedgerBalanced();
});

it('needs a reason to retry, and never lets staff decide on their own order', function () {
    $id = askRefund($this->order, $this->maths->id)->json('data.id');
    $this->order->forceFill(['beneficiary_id' => $this->finance->id])->save();

    Sanctum::actingAs($this->finance);
    $this->postJson("/api/v1/admin/refunds/{$id}/approve")->assertForbidden()->assertJsonPath('code', 'refund.own_order');
    $this->postJson("/api/v1/admin/refunds/{$id}/retry")->assertUnprocessable();
});

it('shows only money paid back as refunded on the receipt, and hides the student from guests', function () {
    $id = askRefund($this->order, $this->maths->id)->json('data.id');
    fakeGateway()->queue(Scenario::PendingForever);
    Sanctum::actingAs($this->finance);
    $this->postJson("/api/v1/admin/refunds/{$id}/approve")->assertOk();

    Sanctum::actingAs($this->student);
    $this->getJson("/api/v1/orders/{$this->order->id}/receipt")
        ->assertJsonPath('data.refunded.amount_minor', 0)
        ->assertJsonPath('data.refund_pending.amount_minor', 1_500);

    expect(App\Domain\Commerce\Receipt::of($this->order->refresh()->load('items', 'payments', 'beneficiary'), 'fr', forGuest: true)['student'])->toBeNull();
});

it('sends a payout again if the gateway never registered it, and gives up after a day', function () {
    $id = askRefund($this->order, $this->maths->id)->json('data.id');
    fakeGateway()->queue(Scenario::Unavailable);
    Sanctum::actingAs($this->finance);
    $this->postJson("/api/v1/admin/refunds/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'failed');

    // A payout stuck in progress with nothing at the gateway: re-sent under the same reference.
    $refund = Refund::query()->findOrFail($id);
    $refund->forceFill(['status' => RefundStatus::Processing, 'attempt' => 7, 'attempted_at' => now()])->save();
    app(App\Domain\Commerce\Actions\RefundLifecycle::class)->sync($refund->refresh());
    confirmRefundPayout($refund);
    expect($refund->refresh()->status)->toBe(RefundStatus::Completed);

    // Another one, still unknown a day later: failed, flagged, retryable.
    $stuck = Refund::query()->create([
        'kind' => 'unapplied', 'order_id' => $this->order->id, 'payment_id' => $this->order->paid_payment_id, 'amount_minor' => 100, 'currency' => 'USD',
        'status' => RefundStatus::Processing, 'attempt' => 1, 'attempted_at' => now()->subHours(25),
    ]);
    app(App\Domain\Commerce\Actions\RefundLifecycle::class)->sync($stuck);
    expect($stuck->refresh()->status)->toBe(RefundStatus::Failed)->and($stuck->flag)->toBe('stuck');
});
