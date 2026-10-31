<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $professor = actingAsProfessor();
    $maths = publishedCourse($professor, 'Algèbre pour l’Exetat'); // 15.00 USD, platform keeps 30% → 4.50

    Sanctum::actingAs(User::factory()->create());
    $orderId = $this->postJson('/api/v1/orders', ['course_ids' => [$maths->id]], ['Idempotency-Key' => 'o-'.Str::ulid()])->json('data.id');
    $paymentId = $this->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'airtel', 'msisdn' => '+243812345678'], ['Idempotency-Key' => 'p-'.$orderId])->json('data.id');
    sendPaymentWebhook($paymentId);
});

it('rolls a day up from the source tables, the same every time', function () {
    $this->artisan('stats:rollup-platform')->assertSuccessful();
    $this->artisan('stats:rollup-platform')->assertSuccessful(); // again: same row

    $today = DB::table('platform_daily_stats')->where('date', now()->toDateString())->sole();
    expect((int) $today->orders_paid)->toBe(1)
        ->and((int) $today->orders_created)->toBe(1)
        ->and((int) $today->enrollments)->toBe(1)
        ->and(json_decode($today->gmv_minor, true))->toBe(['USD' => 1_500])
        ->and(json_decode($today->net_revenue_minor, true))->toBe(['USD' => 450]);
    expect(DB::table('platform_daily_stats')->count())->toBe(2); // today and yesterday
});

it('shows the figures and queues each staff member may see', function () {
    $this->artisan('stats:rollup-platform')->assertSuccessful();

    Sanctum::actingAs(actingAsStaff(Role::Admin));
    $this->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonPath('data.kpis.orders_paid', ['value' => 1, 'previous' => 0])
        ->assertJsonPath('data.kpis.paid_conversion_pct.value', 100)
        ->assertJsonPath('data.money.gmv.USD', ['value' => 1_500, 'previous' => 0])
        ->assertJsonPath('data.money.net_revenue.USD.value', 450)
        ->assertJsonPath('data.queues.applications', ['count' => 0, 'overdue' => 0])
        ->assertJsonPath('data.queues.refunds', null); // admins don't handle refunds without finance.refunds

    Sanctum::actingAs(actingAsStaff(Role::Moderator));
    $this->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonPath('data.money', null)
        ->assertJsonPath('data.series.0.gmv_minor', [])
        ->assertJsonPath('data.queues.stuck_payments', null)
        ->assertJsonPath('data.queues.course_reviews.count', 0);

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
});
