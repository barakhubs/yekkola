<?php

declare(strict_types=1);

use App\Domain\Commerce\Models\Order;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Settings\CommerceSettings;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

const CHILD_PHONE = '+243991112233';
const PARENT_PAYS = '+243827776655';

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor();
    $this->maths = publishedCourse($this->professor, 'Algèbre pour l’Exetat');
    $this->parent = User::factory()->create();
    Sanctum::actingAs($this->parent);
});

function guestOrder(array $payload, string $key = 'guest-1'): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/guest/orders', [
        'course_ids' => [test()->maths->id], 'beneficiary_phone' => CHILD_PHONE, 'bot_token' => 'ok', ...$payload,
    ], ['Idempotency-Key' => $key]);
}

it('lets a signed-in parent buy for a child who has no account yet', function () {
    $orderId = $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id], 'beneficiary_phone' => '0991112233', 'payer_msisdn' => PARENT_PAYS], ['Idempotency-Key' => 'o-1'])
        ->assertCreated()->json('data.id');

    $child = User::query()->where('phone_e164', CHILD_PHONE)->sole();
    expect($child->hasRole(Role::Student->value))->toBeTrue()
        ->and($child->phone_verified_at)->toBeNull()
        ->and(Order::query()->sole()->beneficiary_id)->toBe($child->id);

    // The payer number named on the order is used by default.
    $paymentId = $this->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'airtel'], ['Idempotency-Key' => 'p-1'])
        ->assertCreated()->assertJsonPath('data.payer', fn (string $masked) => str_ends_with($masked, '55'))->json('data.id');
    sendPaymentWebhook($paymentId);

    expect(Enrollment::query()->where('user_id', $child->id)->sole()->isActive())->toBeTrue()
        ->and(Enrollment::query()->where('user_id', $this->parent->id)->exists())->toBeFalse();

    // The child sees the order bought for them, but cannot pay it.
    Sanctum::actingAs($child);
    $this->getJson('/api/v1/me/orders')->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/orders/{$orderId}")->assertOk()->assertJsonPath('data.status', 'paid');
    $this->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'orange'], ['Idempotency-Key' => 'p-2'])->assertNotFound();
});

it('lets a parent with no account pay through a one-time order token', function () {
    $this->app['auth']->forgetGuards(); // no account

    $created = guestOrder([])->assertCreated();
    $orderId = $created->json('data.id');
    $token = $created->json('access_token');
    expect($token)->toBeString()->toHaveLength(48)
        ->and(Order::query()->sole()->buyer_id)->toBeNull()
        ->and(Order::query()->sole()->guest_token_hash)->toBe(hash('sha256', $token));

    $this->getJson("/api/v1/guest/orders/{$orderId}")->assertNotFound();
    $this->getJson("/api/v1/guest/orders/{$orderId}", ['X-Order-Token' => 'wrong'])->assertNotFound();
    $this->getJson("/api/v1/guest/orders/{$orderId}", ['X-Order-Token' => $token])->assertOk()->assertJsonPath('data.status', 'pending');

    $this->postJson("/api/v1/guest/orders/{$orderId}/payments", ['rail' => 'orange'], ['Idempotency-Key' => 'p-1', 'X-Order-Token' => $token])
        ->assertUnprocessable()->assertJsonPath('code', 'payment.payer_required');
    $paymentId = $this->postJson("/api/v1/guest/orders/{$orderId}/payments", ['rail' => 'orange', 'msisdn' => PARENT_PAYS], ['Idempotency-Key' => 'p-2', 'X-Order-Token' => $token])
        ->assertCreated()->json('data.id');
    sendPaymentWebhook($paymentId);

    $this->getJson("/api/v1/guest/orders/{$orderId}", ['X-Order-Token' => $token])->assertJsonPath('data.status', 'paid');
    expect(Enrollment::query()->sole()->user_id)->toBe(User::query()->where('phone_e164', CHILD_PHONE)->value('id'));
});

it('replays a guest order without handing out a second token', function () {
    $this->app['auth']->forgetGuards();
    $first = guestOrder([])->assertCreated();
    $replay = guestOrder([])->assertCreated();

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(Order::query()->count())->toBe(1);
});

it('requires the bot challenge for guests', function () {
    $this->app['auth']->forgetGuards();

    guestOrder(['bot_token' => 'fail'])->assertUnprocessable();
    expect(Order::query()->count())->toBe(0);
});

it('can be switched off by the admin', function () {
    $settings = app(CommerceSettings::class);
    $settings->separate_payer_enabled = false;
    $settings->save();

    $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id], 'beneficiary_phone' => CHILD_PHONE], ['Idempotency-Key' => 'o-1'])
        ->assertForbidden()->assertJsonPath('code', 'order.separate_payer_disabled');
    $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id]], ['Idempotency-Key' => 'o-2'])->assertCreated();
});

it('refuses banned students, other countries, and too many orders for one number', function () {
    $banned = User::factory()->create(['phone_e164' => '+243995554433']);
    $banned->forceFill(['status' => 'banned'])->save();

    $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id], 'beneficiary_phone' => '+243995554433'], ['Idempotency-Key' => 'o-1'])
        ->assertUnprocessable()->assertJsonPath('code', 'order.beneficiary_unavailable');
    $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id], 'beneficiary_phone' => '+256772123456'], ['Idempotency-Key' => 'o-2'])
        ->assertUnprocessable()->assertJsonPath('code', 'phone.country_not_supported');

    for ($i = 0; $i < (int) config('yekkola.checkout.orders_per_student_number_per_day'); $i++) {
        app(Illuminate\Cache\RateLimiter::class)->hit('orders-for-student:'.CHILD_PHONE, 86_400);
    }
    $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id], 'beneficiary_phone' => CHILD_PHONE], ['Idempotency-Key' => 'o-3'])
        ->assertUnprocessable()->assertJsonPath('code', 'order.beneficiary_unavailable');
});

it('never lets someone else\'s unpaid order block the student, or show in their history', function () {
    $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id], 'beneficiary_phone' => CHILD_PHONE], ['Idempotency-Key' => 'o-1'])->assertCreated();

    $child = User::query()->where('phone_e164', CHILD_PHONE)->sole();
    Sanctum::actingAs($child);
    $this->getJson('/api/v1/me/orders')->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id]], ['Idempotency-Key' => 'o-2'])->assertCreated();

    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id], 'beneficiary_phone' => CHILD_PHONE], ['Idempotency-Key' => 'o-3'])->assertCreated();
});

it('reveals nothing about what a number owns, and refunds an owned course instead', function () {
    $child = User::factory()->create(['phone_e164' => CHILD_PHONE]);
    Enrollment::query()->create(['user_id' => $child->id, 'course_id' => $this->maths->id, 'source' => 'admin', 'status' => 'active', 'enrolled_at' => now()]);

    $orderId = $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id], 'beneficiary_phone' => CHILD_PHONE], ['Idempotency-Key' => 'o-1'])
        ->assertCreated()->assertJsonPath('data.total.amount_minor', 1_500)->json('data.id');
    sendPaymentWebhook($this->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'orange', 'msisdn' => PARENT_PAYS], ['Idempotency-Key' => 'p-1'])->json('data.id'));

    expect(Order::query()->sole()->flag->value)->toBe('duplicate_payment')
        ->and(ledgerBalance(App\Domain\Finance\Enums\LedgerAccountType::RefundsPayable))->toBe(-1_500);
});

it('creates no account when the order fails', function () {
    $this->postJson('/api/v1/orders', ['course_ids' => ['01JZZZZZZZZZZZZZZZZZZZZZZZ'], 'beneficiary_phone' => CHILD_PHONE], ['Idempotency-Key' => 'o-1'])
        ->assertUnprocessable()->assertJsonPath('code', 'order.course_unavailable');

    expect(User::query()->where('phone_e164', CHILD_PHONE)->exists())->toBeFalse();
});

it('gives a guest the same token when they retry from another network', function () {
    $this->app['auth']->forgetGuards();
    $first = guestOrder([])->assertCreated();
    $retry = $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->postJson('/api/v1/guest/orders', [
        'course_ids' => [$this->maths->id], 'beneficiary_phone' => CHILD_PHONE, 'bot_token' => 'ok',
    ], ['Idempotency-Key' => 'guest-1'])->assertCreated();

    expect($retry->json('access_token'))->toBe($first->json('access_token'))
        ->and($retry->json('data.id'))->toBe($first->json('data.id'));
});

it('onboards a student registered by a parent at their first sign-in', function () {
    $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id], 'beneficiary_phone' => CHILD_PHONE], ['Idempotency-Key' => 'o-1'])->assertCreated();
    $this->app['auth']->forgetGuards();

    signInWeb(CHILD_PHONE)->assertOk()->assertJsonPath('data.is_new_user', true);
});
