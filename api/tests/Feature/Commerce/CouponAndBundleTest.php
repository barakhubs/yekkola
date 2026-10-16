<?php

declare(strict_types=1);

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\CouponRedemption;
use App\Domain\Commerce\Models\Order;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Models\Activity;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor();
    $this->maths = publishedCourse($this->professor, 'Algèbre pour l’Exetat'); // 15.00 USD, 70% share
    $this->physics = publishedCourse($this->professor, 'Physique de base', ['price_minor' => 2_000]);
    $this->other = actingAsProfessor('Jean Mukendi');
    $this->chemistry = publishedCourse($this->other, 'Chimie organique', ['price_minor' => 1_000]);

    $this->admin = actingAsStaff(Role::Admin);
    $this->student = User::factory()->create();
});

function coupon(array $attributes): Coupon
{
    return Coupon::query()->create([
        'scope' => 'platform', 'discount_type' => 'percent', 'value' => 20, 'funded_by' => 'platform', ...$attributes,
        'code' => Coupon::normalize($attributes['code'] ?? 'RENTREE'),
    ]);
}

function buy(array $payload, string $key = 'order-1'): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => $key]);
}

function payAndConfirm(string $orderId, string $key = 'pay-1'): void
{
    $paymentId = test()->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'orange', 'msisdn' => '+243812345678'], ['Idempotency-Key' => $key])->assertCreated()->json('data.id');
    sendPaymentWebhook($paymentId);
}

it('applies a platform-funded percent coupon: the professor still earns their share of the list price', function () {
    $this->postJson('/api/v1/admin/coupons', [
        'code' => 'rentree', 'scope' => 'platform', 'discount_type' => 'percent', 'value' => 20, 'funded_by' => 'platform',
    ])->assertCreated()->assertJsonPath('data.code', 'RENTREE');
    expect(Activity::query()->where('event', 'coupon.created')->sole()->causer_id)->toBe($this->admin->id);

    Sanctum::actingAs($this->student);
    $this->postJson('/api/v1/checkout/quote', ['course_ids' => [$this->maths->id], 'coupon_code' => 'Rentree'])
        ->assertOk()
        ->assertJsonPath('data.discount.amount_minor', 300)
        ->assertJsonPath('data.total.amount_minor', 1_200);

    $orderId = buy(['course_ids' => [$this->maths->id], 'coupon_code' => 'rentree'])->assertCreated()->json('data.id');
    payAndConfirm($orderId);

    expect(Order::query()->sole()->status)->toBe(OrderStatus::Paid)
        ->and(CouponRedemption::query()->count())->toBe(1)
        ->and(ledgerBalance(LedgerAccountType::GatewayClearing))->toBe(1_200)
        ->and(ledgerBalance(LedgerAccountType::DiscountsPlatform))->toBe(300)
        ->and(ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id))->toBe(-1_050) // 70% of 15.00
        ->and(ledgerBalance(LedgerAccountType::PlatformRevenue))->toBe(-450);
    assertLedgerBalanced();
});

it('lets professors fund coupons on their own courses only', function () {
    Sanctum::actingAs($this->professor->user);
    $this->postJson('/api/v1/professor/coupons', ['code' => 'MAMAN', 'course_id' => $this->chemistry->id, 'discount_type' => 'percent', 'value' => 50])
        ->assertUnprocessable()->assertJsonValidationErrors('course_id');
    $this->postJson('/api/v1/professor/coupons', ['code' => 'MAMAN', 'course_id' => $this->maths->id, 'discount_type' => 'percent', 'value' => 50, 'funded_by' => 'platform'])
        ->assertUnprocessable()->assertJsonValidationErrors('funded_by');
    $this->postJson('/api/v1/professor/coupons', ['code' => 'MAMAN', 'course_id' => $this->maths->id, 'discount_type' => 'percent', 'value' => 50])
        ->assertCreated()->assertJsonPath('data.funded_by', 'professor')->assertJsonPath('data.scope', 'course');

    Sanctum::actingAs($this->student);
    buy(['course_ids' => [$this->physics->id], 'coupon_code' => 'MAMAN'])->assertUnprocessable()->assertJsonPath('code', 'coupon.not_applicable');
    $orderId = buy(['course_ids' => [$this->maths->id], 'coupon_code' => 'MAMAN'], 'order-2')->assertCreated()->json('data.id');
    payAndConfirm($orderId);

    // The platform keeps its 30% of the list price (4.50); the professor gets the rest of the 7.50 paid.
    expect(ledgerBalance(LedgerAccountType::PlatformRevenue))->toBe(-450)
        ->and(ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id))->toBe(-300)
        ->and(ledgerBalance(LedgerAccountType::DiscountsPlatform))->toBe(0);
    assertLedgerBalanced();
});

it('splits a fixed coupon across courses in whole francs', function () {
    foreach ([$this->maths, $this->physics] as $i => $course) {
        $course->update(['currency' => 'CDF', 'price_minor' => [3_000_000, 1_500_000][$i]]);
    }
    coupon(['code' => 'CDF1000', 'discount_type' => 'fixed', 'value' => 100_000, 'currency' => 'CDF']);

    Sanctum::actingAs($this->student);
    $items = buy(['course_ids' => [$this->maths->id, $this->physics->id], 'coupon_code' => 'cdf1000'])->assertCreated()->json('data.items');

    expect(array_column(array_column($items, 'discount'), 'amount_minor'))->toBe([66_700, 33_300])
        ->and(array_sum(array_column(array_column($items, 'price'), 'amount_minor')) % 100)->toBe(0);
});

it('enforces coupon limits, counting open orders as reservations', function () {
    coupon(['code' => 'UNIQUE', 'max_redemptions' => 1]);

    Sanctum::actingAs($this->student);
    buy(['course_ids' => [$this->maths->id], 'coupon_code' => 'UNIQUE'])->assertCreated();

    Sanctum::actingAs($second = User::factory()->create());
    buy(['course_ids' => [$this->maths->id], 'coupon_code' => 'UNIQUE'])->assertUnprocessable()->assertJsonPath('code', 'coupon.exhausted');

    $this->travel(16)->minutes(); // the first order expires and releases its reservation
    buy(['course_ids' => [$this->maths->id], 'coupon_code' => 'UNIQUE'], 'order-2')->assertCreated();

    coupon(['code' => 'ONCE', 'per_user_limit' => 1]);
    buy(['course_ids' => [$this->physics->id], 'coupon_code' => 'ONCE'], 'order-3')->assertCreated();
    buy(['course_ids' => [$this->chemistry->id], 'coupon_code' => 'ONCE'], 'order-4')->assertUnprocessable()->assertJsonPath('code', 'coupon.already_used');
});

it('refuses unknown, inactive and expired codes the same way', function (array $attributes) {
    coupon(['code' => 'PROMO', ...$attributes]);
    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/checkout/quote', ['course_ids' => [$this->maths->id], 'coupon_code' => $attributes === [] ? 'NOPE' : 'PROMO'])
        ->assertUnprocessable()->assertJsonPath('code', 'coupon.invalid');
})->with([
    'unknown' => [[]],
    'inactive' => [['is_active' => false]],
    'expired' => [['ends_at' => now()->subDay()]],
    'not started' => [['starts_at' => now()->addDay()]],
]);

it('turns a 100% coupon into a free enrollment, with no payment or ledger posting', function () {
    coupon(['code' => 'OFFERT', 'value' => 100]);
    Sanctum::actingAs($this->student);

    buy(['course_ids' => [$this->maths->id], 'coupon_code' => 'offert'])->assertCreated()->assertJsonPath('data.status', 'paid');

    expect(Enrollment::query()->sole()->source->value)->toBe('coupon')
        ->and(CouponRedemption::query()->count())->toBe(1)
        ->and(LedgerTransaction::query()->count())->toBe(0);
});

it('never leaves a paid total below what the operators can collect', function () {
    $this->maths->update(['price_minor' => 150]);
    coupon(['code' => 'MOITIE', 'value' => 50]);
    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/checkout/quote', ['course_ids' => [$this->maths->id], 'coupon_code' => 'MOITIE'])
        ->assertJsonPath('data.total.amount_minor', 100) // FakeGateway minimum: 1.00 USD
        ->assertJsonPath('data.discount.amount_minor', 50);
});

it('sells bundles, lowering the price for courses already owned', function () {
    $bundle = $this->postJson('/api/v1/admin/bundles', [
        'title' => 'Pack Sciences', 'price_minor' => 3_000, 'currency' => 'USD', 'status' => 'active',
        'course_ids' => [$this->maths->id, $this->physics->id],
    ])->assertCreated()->assertJsonPath('data.list_total.amount_minor', 3_500)->json('data');
    $this->postJson('/api/v1/admin/bundles', ['title' => 'Trop cher', 'price_minor' => 4_000, 'currency' => 'USD', 'course_ids' => [$this->maths->id, $this->physics->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('price_minor');

    $this->getJson("/api/v1/bundles/{$bundle['slug']}")->assertOk()->assertJsonCount(2, 'data.courses');

    Sanctum::actingAs($this->student);
    $this->postJson('/api/v1/checkout/quote', ['bundle_id' => $bundle['id']])->assertJsonPath('data.total.amount_minor', 3_000);
    $this->postJson('/api/v1/checkout/quote', ['bundle_id' => $bundle['id'], 'coupon_code' => 'X'])->assertJsonPath('code', 'coupon.not_applicable');

    Enrollment::query()->create(['user_id' => $this->student->id, 'course_id' => $this->maths->id, 'source' => 'admin', 'status' => 'active', 'enrolled_at' => now()]);
    $orderId = buy(['bundle_id' => $bundle['id']])->assertCreated()
        ->assertJsonPath('data.total.amount_minor', 1_714) // 30.00 × 20/35, rounded down
        ->json('data.id');
    payAndConfirm($orderId);

    expect(Enrollment::query()->where('course_id', $this->physics->id)->sole()->source->value)->toBe('bundle')
        ->and(ledgerBalance(LedgerAccountType::ProfessorHeld, $this->professor->id))->toBe(-1_199); // 70% of 17.14
    assertLedgerBalanced();
});

it('lets professors bundle only their own courses', function () {
    Sanctum::actingAs($this->professor->user);

    $this->postJson('/api/v1/professor/bundles', ['title' => 'Mélange', 'price_minor' => 2_000, 'currency' => 'USD', 'course_ids' => [$this->maths->id, $this->chemistry->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('course_ids');
    $this->postJson('/api/v1/professor/bundles', ['title' => 'Mon pack', 'price_minor' => 3_000, 'currency' => 'USD', 'course_ids' => [$this->maths->id, $this->physics->id]])
        ->assertCreated()->assertJsonPath('data.status', 'draft');
});
