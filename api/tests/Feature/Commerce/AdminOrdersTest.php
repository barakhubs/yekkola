<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Activity;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $professor = actingAsProfessor();
    $this->maths = publishedCourse($professor, 'Algèbre pour l’Exetat');
    $this->student = User::factory()->create(['name' => 'Grâce Mbuyi', 'phone_e164' => '+243812000111']);
    $this->admin = actingAsStaff(Role::Admin);
});

it('finds a stuck payment and settles it with a recheck', function () {
    Sanctum::actingAs($this->student);
    $orderId = $this->postJson('/api/v1/orders', ['course_ids' => [$this->maths->id]], ['Idempotency-Key' => 'o-'.Str::ulid()])->json('data.id');
    $paymentId = $this->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'airtel', 'msisdn' => '+243812345678'], ['Idempotency-Key' => 'p-1'])->json('data.id');
    // No webhook: the payment waits.
    $this->travel(11)->minutes();

    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/admin/orders?filter[stuck]=1')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.student.name', 'Grâce Mbuyi')
        ->assertJsonPath('data.0.student.phone', fn (string $p) => ! str_contains($p, '000111'));
    $this->getJson('/api/v1/admin/orders?filter[phone]=0812000111')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/orders?filter[phone]=0899999999')->assertJsonCount(0, 'data');

    $this->postJson("/api/v1/admin/payments/{$paymentId}/recheck")->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.payments.0.status', 'succeeded')
        ->assertJsonPath('data.items.0.enrollment_status', 'active')
        ->assertJsonPath('data.ledger.0.type', 'sale');
    expect(Activity::query()->where('event', 'payment.rechecked')->sole()->properties['after'])->toBe('succeeded');

    $this->getJson('/api/v1/admin/orders?filter[stuck]=1')->assertJsonCount(0, 'data');
});

it('is for staff who can see commerce', function () {
    Sanctum::actingAs(actingAsStaff(Role::Moderator));
    $this->getJson('/api/v1/admin/orders')->assertForbidden();

    Sanctum::actingAs($this->student);
    $this->getJson('/api/v1/admin/orders')->assertForbidden();
});
