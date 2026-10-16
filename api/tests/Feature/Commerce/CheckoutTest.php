<?php

declare(strict_types=1);

use App\Domain\Commerce\Actions\PaymentLifecycle;
use App\Domain\Commerce\Enums\OrderFlag;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\PaymentEvent;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\EnrollmentStatus;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Integrations\Payments\GatewayResult;
use App\Integrations\Payments\GatewayStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// Payer numbers pick the FakeGateway scenario by their last 4 digits (PRD-05 FR-18).
const PAYS = '+243812345678';
const INSUFFICIENT_FUNDS = '+243812340001';
const PENDING_FOREVER = '+243812340002';
const LATE_SUCCESS = '+243812340003';
const REVERSAL = '+243812340004';
const UNAVAILABLE = '+243812340006';
const OUTCOME_UNKNOWN = '+243812340007';
const AMOUNT_MISMATCH = '+243812340008';
const FAIL_THEN_SUCCEED = '+243812340005';

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor();
    $this->maths = publishedCourse($this->professor, 'Algèbre pour l’Exetat'); // 15.00 USD
    $this->physics = publishedCourse($this->professor, 'Physique de base', ['price_minor' => 2_000, 'revenue_share_bps' => 8_000]);
    $this->free = publishedCourse($this->professor, 'Méthodologie', ['is_free' => true, 'price_minor' => null, 'currency' => null]);

    $this->student = User::factory()->create();
    Sanctum::actingAs($this->student);
});

function order(array $courseIds, string $key = 'order-key-1'): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/orders', ['course_ids' => $courseIds], ['Idempotency-Key' => $key]);
}

function pay(string $orderId, string $msisdn, string $rail = 'orange', string $key = 'pay-key-1'): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => $rail, 'msisdn' => $msisdn], ['Idempotency-Key' => $key]);
}

function reconcile(): void
{
    test()->artisan('commerce:reconcile')->assertSuccessful();
}

it('enrolls in a free course in one tap, idempotently', function () {
    $this->postJson("/api/v1/courses/{$this->free->id}/enroll")->assertOk()->assertJsonPath('data.source', 'free');
    $this->postJson("/api/v1/courses/{$this->free->id}/enroll")->assertOk();

    expect(Enrollment::query()->count())->toBe(1)
        ->and(Order::query()->count())->toBe(0)
        ->and(LedgerTransaction::query()->count())->toBe(0);

    $this->postJson("/api/v1/courses/{$this->maths->id}/enroll")->assertUnprocessable()->assertJsonPath('code', 'enrollment.not_free');
});

it('quotes server-side and leaves out courses already owned', function () {
    $this->postJson('/api/v1/checkout/quote', ['course_ids' => [$this->maths->id, $this->physics->id]])
        ->assertOk()
        ->assertJsonPath('data.total', ['amount_minor' => 3_500, 'currency' => 'USD']);

    Enrollment::query()->create(['user_id' => $this->student->id, 'course_id' => $this->maths->id, 'source' => 'admin', 'status' => 'active', 'enrolled_at' => now()]);

    $this->postJson('/api/v1/checkout/quote', ['course_ids' => [$this->maths->id, $this->physics->id]])
        ->assertJsonPath('data.total.amount_minor', 2_000)
        ->assertJsonPath('data.excluded_course_ids', [$this->maths->id]);
    $this->postJson('/api/v1/checkout/quote', ['course_ids' => [$this->maths->id]])->assertJsonPath('code', 'order.nothing_to_buy');
    $this->postJson('/api/v1/checkout/quote', ['course_ids' => [$this->free->id]])->assertJsonPath('code', 'order.free_course');

    $this->physics->update(['currency' => 'CDF', 'price_minor' => 500_000]);
    $this->postJson('/api/v1/checkout/quote', ['course_ids' => [$this->physics->id, $this->free->id]])->assertUnprocessable();
});

it('refuses mixed currencies in one order', function () {
    $this->physics->update(['currency' => 'CDF', 'price_minor' => 500_000]);

    order([$this->maths->id, $this->physics->id])->assertUnprocessable()->assertJsonPath('code', 'order.mixed_currency');
});

it('requires an idempotency key and snapshots prices and revenue shares', function () {
    $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id]])->assertBadRequest()->assertJsonPath('code', 'idempotency.key_missing');

    $first = order([$this->maths->id, $this->physics->id])->assertCreated();
    $again = order([$this->maths->id, $this->physics->id])->assertCreated();

    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(Order::query()->count())->toBe(1)
        ->and($first->json('data.status'))->toBe('pending')
        ->and($first->json('data.number'))->toStartWith('YK-');

    $items = Order::query()->sole()->items->keyBy('course_id');
    expect($items[$this->maths->id]->revenue_share_bps)->toBe(7_000) // platform default
        ->and($items[$this->physics->id]->revenue_share_bps)->toBe(8_000); // course override

    $this->maths->update(['price_minor' => 9_900]);
    expect(Order::query()->sole()->total_minor)->toBe(3_500);
});

it('pays: webhooks delivered three times grant access once and post one balanced sale', function () {
    $orderId = order([$this->maths->id, $this->physics->id])->json('data.id');

    $payment = pay($orderId, PAYS)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
    expect($payment['payer'])->not->toContain('345678');

    foreach (['evt-1', 'evt-1', 'evt-2'] as $eventId) {
        sendPaymentWebhook($payment['id'], $eventId)->assertOk();
    }

    expect(PaymentEvent::query()->count())->toBe(2)
        ->and(Order::query()->sole()->status)->toBe(OrderStatus::Paid)
        ->and(Payment::query()->sole()->status)->toBe(PaymentStatus::Succeeded)
        ->and(Enrollment::query()->where('user_id', $this->student->id)->where('source', 'purchase')->count())->toBe(2)
        ->and(LedgerTransaction::query()->where('type', LedgerTransactionType::Sale)->count())->toBe(1)
        ->and(ledgerBalance(LedgerAccountType::GatewayClearing))->toBe(3_500)
        ->and(ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id))->toBe(-(1_050 + 1_600)) // 70% of 15 + 80% of 20
        ->and(ledgerBalance(LedgerAccountType::PlatformRevenue))->toBe(-(450 + 400));
    assertLedgerBalanced();

    $this->getJson("/api/v1/orders/{$orderId}")->assertJsonPath('data.status', 'paid');
    $this->getJson('/api/v1/me/orders')->assertJsonCount(1, 'data');
});

it('moves the waiting screen on by polling when no webhook arrives', function () {
    $orderId = order([$this->maths->id])->json('data.id');
    pay($orderId, PAYS)->assertCreated();

    $this->travel(11)->seconds();

    $this->getJson("/api/v1/orders/{$orderId}")->assertJsonPath('data.status', 'paid');
});

it('lets the student retry after a failure, one attempt at a time', function () {
    $orderId = order([$this->maths->id])->json('data.id');

    pay($orderId, INSUFFICIENT_FUNDS)->assertCreated()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.failure_code', 'insufficient_funds')
        ->assertJsonPath('data.failure_reason', 'Insufficient balance on this mobile-money account.');

    pay($orderId, PENDING_FOREVER, 'airtel', 'pay-key-2')->assertCreated()->assertJsonPath('data.status', 'pending');
    pay($orderId, PAYS, 'mpesa', 'pay-key-3')->assertConflict()->assertJsonPath('code', 'payment.in_progress');

    pay($orderId, PENDING_FOREVER, 'airtel', 'pay-key-2')->assertCreated(); // same key: replayed, not a new attempt
    expect(Payment::query()->count())->toBe(2);
});

it('expires unpaid orders, and a late success still grants access, flagged', function () {
    $stuck = order([$this->maths->id])->json('data.id');
    pay($stuck, PENDING_FOREVER)->assertCreated();
    $late = order([$this->physics->id], 'order-key-2')->json('data.id');
    pay($late, LATE_SUCCESS, key: 'pay-key-2')->assertCreated();

    $this->travel(16)->minutes();
    reconcile(); // expires both orders; first check of the late payment: still pending

    expect(Order::query()->find($stuck)->status)->toBe(OrderStatus::Expired)
        ->and(Payment::query()->where('order_id', $stuck)->sole()->status)->toBe(PaymentStatus::Expired);
    pay($stuck, PAYS, key: 'pay-key-9')->assertConflict()->assertJsonPath('code', 'order.not_payable');

    $this->travel(61)->minutes();
    reconcile(); // hourly late check: still pending
    $this->travel(61)->minutes();
    reconcile(); // next one: succeeded

    $order = Order::query()->find($late);
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->flag)->toBe(OrderFlag::LateSuccess)
        ->and(Enrollment::query()->where('course_id', $this->physics->id)->sole()->isActive())->toBeTrue()
        ->and(Order::query()->find($stuck)->status)->toBe(OrderStatus::Expired);
    assertLedgerBalanced();
});

it('revokes access and reverses the sale when the operator reverses a payment', function () {
    $orderId = order([$this->maths->id])->json('data.id');
    $paymentId = pay($orderId, REVERSAL)->json('data.id');

    sendPaymentWebhook($paymentId, 'evt-1'); // check 1: succeeded
    expect(Enrollment::query()->sole()->isActive())->toBeTrue();

    sendPaymentWebhook($paymentId, 'evt-2'); // check 2: reversed
    sendPaymentWebhook($paymentId, 'evt-3');

    expect(Order::query()->sole()->status)->toBe(OrderStatus::Reversed)
        ->and(Payment::query()->sole()->status)->toBe(PaymentStatus::Reversed)
        ->and(Enrollment::query()->sole()->status)->toBe(EnrollmentStatus::Revoked)
        ->and(LedgerTransaction::query()->where('type', LedgerTransactionType::SaleReversal)->count())->toBe(1)
        ->and(ledgerBalance(LedgerAccountType::GatewayClearing))->toBe(0)
        ->and(ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id))->toBe(0)
        ->and(ledgerBalance(LedgerAccountType::PlatformRevenue))->toBe(0);
    assertLedgerBalanced();
});

it('grants nothing when the confirmed amount differs, but records the money as owed back', function () {
    $orderId = order([$this->maths->id])->json('data.id');
    $paymentId = pay($orderId, AMOUNT_MISMATCH)->json('data.id');
    sendPaymentWebhook($paymentId);

    expect(Order::query()->sole()->flag)->toBe(OrderFlag::AmountMismatch)
        ->and(Order::query()->sole()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and(Enrollment::query()->count())->toBe(0)
        ->and(LedgerTransaction::query()->where('type', LedgerTransactionType::Sale)->count())->toBe(0)
        ->and(ledgerBalance(LedgerAccountType::GatewayClearing))->toBe(1_499) // what the gateway really holds
        ->and(ledgerBalance(LedgerAccountType::RefundsPayable))->toBe(-1_499);
    assertLedgerBalanced();

    pay($orderId, PAYS, key: 'pay-key-2')->assertConflict()->assertJsonPath('code', 'order.not_payable');
});

it('never charges twice when an expired order succeeds after the course was bought again', function () {
    $first = order([$this->maths->id])->json('data.id');
    pay($first, LATE_SUCCESS)->assertCreated();

    $this->travel(16)->minutes();
    reconcile(); // first order expires; its payment is still pending at the gateway

    $second = order([$this->maths->id], 'order-key-2')->assertCreated()->json('data.id');
    sendPaymentWebhook(pay($second, PAYS, key: 'pay-key-2')->json('data.id'));

    $this->travel(61)->minutes();
    reconcile(); // hourly late check: still pending
    $this->travel(61)->minutes();
    reconcile(); // the first prompt is approved at last

    $enrollment = Enrollment::query()->sole();
    expect(Order::query()->find($first)->status)->toBe(OrderStatus::Expired)
        ->and(Order::query()->find($first)->flag)->toBe(OrderFlag::DuplicatePayment)
        ->and(Order::query()->find($second)->status)->toBe(OrderStatus::Paid)
        ->and($enrollment->isActive())->toBeTrue()
        ->and($enrollment->order_item_id)->toBe(Order::query()->find($second)->items->sole()->id)
        ->and(LedgerTransaction::query()->where('type', LedgerTransactionType::Sale)->count())->toBe(1)
        ->and(ledgerBalance(LedgerAccountType::GatewayClearing))->toBe(3_000)
        ->and(ledgerBalance(LedgerAccountType::RefundsPayable))->toBe(-1_500);
    assertLedgerBalanced();
});

it('flags a second success on a paid order and owes it back', function () {
    $orderId = order([$this->maths->id])->json('data.id');
    sendPaymentWebhook(pay($orderId, FAIL_THEN_SUCCEED)->json('data.id'), 'evt-a'); // check 1: failed

    sendPaymentWebhook(pay($orderId, PAYS, 'airtel', 'pay-key-2')->json('data.id'), 'evt-b');
    expect(Order::query()->sole()->status)->toBe(OrderStatus::Paid);

    $this->travel(61)->minutes();
    reconcile(); // the failed attempt succeeds late

    expect(Order::query()->sole()->flag)->toBe(OrderFlag::DuplicatePayment)
        ->and(Payment::query()->where('flag', OrderFlag::DuplicatePayment)->count())->toBe(1)
        ->and(LedgerTransaction::query()->where('type', LedgerTransactionType::Sale)->count())->toBe(1)
        ->and(ledgerBalance(LedgerAccountType::RefundsPayable))->toBe(-1_500)
        ->and(Enrollment::query()->count())->toBe(1);
    assertLedgerBalanced();
});

it('allows one open order per course', function () {
    $open = order([$this->maths->id])->json('data.id');

    order([$this->maths->id, $this->physics->id], 'order-key-2')
        ->assertConflict()
        ->assertJsonPath('code', 'order.open_order_exists')
        ->assertJsonPath('order_id', $open);
});

it('posts the gateway fee and ignores results for another reference', function () {
    $orderId = order([$this->maths->id])->json('data.id');
    $payment = Payment::query()->findOrFail(pay($orderId, PENDING_FOREVER)->json('data.id'));
    $lifecycle = app(PaymentLifecycle::class);
    $usd = Currency::USD;

    $lifecycle->apply($payment, new GatewayResult('someone-else', 'g-1', GatewayStatus::Succeeded, Money::of(1_500, $usd), Money::zero($usd)));
    expect(Order::query()->sole()->status)->toBe(OrderStatus::AwaitingPayment);

    $lifecycle->apply($payment, new GatewayResult($payment->id, 'g-1', GatewayStatus::Succeeded, Money::of(1_500, $usd), Money::of(45, $usd)));

    expect(Order::query()->sole()->status)->toBe(OrderStatus::Paid)
        ->and(ledgerBalance(LedgerAccountType::GatewayFees))->toBe(45)
        ->and(ledgerBalance(LedgerAccountType::GatewayClearing))->toBe(1_455)
        ->and(ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id))->toBe(-1_050);
    assertLedgerBalanced();
});

it('handles gateway outages without charging twice', function () {
    $orderId = order([$this->maths->id])->json('data.id');

    pay($orderId, UNAVAILABLE)->assertCreated()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_code', 'gateway_unavailable');

    pay($orderId, OUTCOME_UNKNOWN, key: 'pay-key-2')->assertCreated()->assertJsonPath('data.status', 'pending');
    $this->travel(3)->minutes();
    reconcile();

    expect(Order::query()->sole()->status)->toBe(OrderStatus::Paid)
        ->and(Payment::query()->where('status', PaymentStatus::Succeeded)->count())->toBe(1);
});

it('only offers operators the admin has enabled', function () {
    $settings = app(CommerceSettings::class);
    $settings->enabled_rails = ['airtel', 'mpesa'];
    $settings->save();

    $this->getJson('/api/v1/checkout/rails?currency=USD')->assertOk()->assertJsonPath('data', ['orange' => false, 'airtel' => true, 'mpesa' => true]);

    $orderId = order([$this->maths->id])->json('data.id');
    pay($orderId, PAYS)->assertUnprocessable()->assertJsonPath('code', 'payment.rail_unavailable');
});

it('keeps orders private to their buyer', function () {
    $orderId = order([$this->maths->id])->json('data.id');

    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/v1/orders/{$orderId}")->assertNotFound();
    pay($orderId, PAYS)->assertNotFound();
});

it('verifies webhooks and keeps events for unknown references', function () {
    $this->call('POST', '/api/v1/webhooks/payments/fake', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => 'forged'], '{"event_id":"x","kind":"collection","reference":"y"}')
        ->assertUnauthorized();
    $this->postJson('/api/v1/webhooks/payments/aggregator', [])->assertNotFound();

    $request = fakeGateway()->webhookRequest(['event_id' => 'evt-early', 'kind' => 'collection', 'reference' => '01JUNKNOWNREFERENCE0000000']);
    $this->call('POST', '/api/v1/webhooks/payments/fake', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => $request->headers->get('X-Fake-Signature')], $request->getContent())
        ->assertOk();

    expect(PaymentEvent::query()->sole()->error)->toBe('unknown_reference')
        ->and(PaymentEvent::query()->sole()->processed_at)->toBeNull();
});

it('keeps the ledger append-only', function () {
    $orderId = order([$this->maths->id])->json('data.id');
    sendPaymentWebhook(pay($orderId, PAYS)->json('data.id'));

    expect(fn () => DB::table('ledger_entries')->update(['amount_minor' => 1]))->toThrow(Illuminate\Database\QueryException::class);
});
