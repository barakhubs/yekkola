<?php

declare(strict_types=1);

use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor();
    $maths = publishedCourse($this->professor, 'Algèbre pour l’Exetat'); // 15.00 USD, 70% → 10.50

    Sanctum::actingAs(User::factory()->create());
    $orderId = $this->postJson('/api/v1/orders', ['course_ids' => [$maths->id]], ['Idempotency-Key' => 'o-'.Str::ulid()])->json('data.id');
    $paymentId = $this->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'airtel', 'msisdn' => '+243812345678'], ['Idempotency-Key' => 'p-'.$orderId])->json('data.id');
    sendPaymentWebhook($paymentId);
});

it('shows finance every account with its totals, and the transactions on one', function () {
    Sanctum::actingAs(actingAsStaff(Role::Admin, [Permission::FinanceView]));

    $held = collect($this->getJson('/api/v1/admin/ledger/accounts?filter[owner_type]=professor')->assertOk()->json('data'))->sole();
    expect($held)->toMatchArray([
        'type' => 'professor_held', 'owner_name' => 'Marie Kabila', 'currency' => 'USD',
        'debits_minor' => 0, 'credits_minor' => 1_050, 'balance_minor' => -1_050,
    ]);

    $this->getJson('/api/v1/admin/ledger/transactions?filter[account_id]='.$held['id'])->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'sale')
        ->assertJsonPath('data.0.entries', fn (array $entries) => collect($entries)->contains(fn ($e) => $e['owner_name'] === 'Marie Kabila' && $e['direction'] === 'credit' && $e['amount_minor'] === 1_050));

    expect(LedgerAccount::query()->count())->toBeGreaterThan(1);
});

it('is finance-only', function () {
    Sanctum::actingAs(actingAsStaff(Role::Moderator));

    $this->getJson('/api/v1/admin/ledger/accounts')->assertForbidden();
    $this->getJson('/api/v1/admin/ledger/transactions')->assertForbidden();
});
