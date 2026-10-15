<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Platform\Models\Activity;
use App\Domain\Professors\Enums\KycStatus;
use App\Domain\Professors\Enums\ProfessorStatus;
use Laravel\Sanctum\Sanctum;

function setPayoutMethod(string $phone = '0972345678', string $rail = 'airtel'): Illuminate\Testing\TestResponse
{
    test()->postJson('/api/v1/professor/payout-method/otp')->assertAccepted();
    $own = auth()->user()->phone_e164;

    return test()->putJson('/api/v1/professor/payout-method', [
        'rail' => $rail,
        'phone' => $phone,
        'name' => 'Marie Kabila',
        'code' => lastSmsCode($own),
    ]);
}

it('sets the payout method after confirming a code sent to the account number', function () {
    $profile = actingAsProfessor();

    setPayoutMethod()
        ->assertOk()
        ->assertJsonPath('data.payout_method.rail', 'airtel')
        ->assertJsonPath('data.payout_method.phone', '+243 9•• ••• 678')
        ->assertJsonPath('data.payout_hold_until', null);

    expect($profile->refresh()->payout_msisdn)->toBe('+243972345678')
        ->and(Activity::query()->where('event', 'professor.payout_method_changed')->sole()->properties->get('after')['msisdn'])->toBe('+243 9•• ••• 678');
});

it('stores the payout number encrypted', function () {
    $profile = actingAsProfessor();
    setPayoutMethod();

    $raw = Illuminate\Support\Facades\DB::table('professor_profiles')->where('id', $profile->id)->value('payout_msisdn');

    expect($raw)->not->toContain('972345678');
});

it('holds payouts for 48 hours after a change', function () {
    $profile = actingAsProfessor();
    setPayoutMethod();
    $this->travel(61)->seconds();

    setPayoutMethod('0812349999', 'mpesa')->assertOk()->assertJsonPath('data.payout_hold_until', fn ($v) => $v !== null);

    $profile->refresh()->update(['kyc_status' => KycStatus::Verified]);
    expect($profile->isPayable())->toBeFalse();

    $this->travel(49)->hours();
    expect($profile->refresh()->isPayable())->toBeTrue();
});

it('rejects a wrong confirmation code', function () {
    actingAsProfessor();
    $this->postJson('/api/v1/professor/payout-method/otp');
    $code = lastSmsCode(auth()->user()->phone_e164);

    $this->putJson('/api/v1/professor/payout-method', [
        'rail' => 'orange', 'phone' => '0842345678', 'name' => 'Marie', 'code' => $code === '000000' ? '111111' : '000000',
    ])->assertJsonPath('code', 'otp.invalid');
});

it('pays only active, verified professors with a payout method', function () {
    $profile = actingAsProfessor();
    expect($profile->isPayable())->toBeFalse();

    setPayoutMethod();
    expect($profile->refresh()->isPayable())->toBeFalse(); // KYC not verified (BR-01)

    $profile->update(['kyc_status' => KycStatus::Verified]);
    expect($profile->refresh()->isPayable())->toBeTrue();

    $profile->update(['status' => ProfessorStatus::Suspended]);
    expect($profile->refresh()->isPayable())->toBeFalse(); // BR-03
});

it('lets professor managers suspend and reinstate with a reason', function () {
    $profile = actingAsProfessor();

    actingAsStaff(Role::Moderator);
    $this->postJson("/api/v1/admin/professors/{$profile->id}/suspend", ['note' => 'Contenu signalé.'])->assertForbidden();

    actingAsStaff(Role::Admin);
    $this->postJson("/api/v1/admin/professors/{$profile->id}/suspend")->assertUnprocessable();
    $this->postJson("/api/v1/admin/professors/{$profile->id}/suspend", ['note' => 'Contenu signalé.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    $this->getJson("/api/v1/professors/{$profile->slug}")->assertNotFound();

    $this->postJson("/api/v1/admin/professors/{$profile->id}/reinstate", ['note' => 'Vérifié, rien à signaler.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect(Activity::query()->whereIn('event', ['professor.suspended', 'professor.reinstated'])->count())->toBe(2);
});

it('lets admins override the revenue share, audited', function () {
    $profile = actingAsProfessor();
    actingAsStaff(Role::Admin);

    $this->patchJson("/api/v1/admin/professors/{$profile->id}", ['revenue_share_bps' => 12_000, 'note' => 'x'])->assertUnprocessable();

    $this->patchJson("/api/v1/admin/professors/{$profile->id}", ['revenue_share_bps' => 8_000, 'note' => 'Professeur partenaire.'])
        ->assertOk()
        ->assertJsonPath('data.revenue_share_bps', 8_000);

    $this->patchJson("/api/v1/admin/professors/{$profile->id}", ['revenue_share_bps' => null, 'note' => 'Retour au défaut.'])
        ->assertOk()
        ->assertJsonPath('data.revenue_share_bps', null);

    expect(Activity::query()->where('event', 'professor.revenue_share_changed')->count())->toBe(2);
});

it('lists and searches professors for admins', function () {
    actingAsProfessor('Amani Byamungu');
    actingAsStaff(Role::Admin);

    $this->getJson('/api/v1/admin/professors?filter[search]=amani')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/professors?filter[search]=zzz')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/professors?per_page=1')->assertOk()->assertJsonPath('meta.per_page', 1);
});

it('keeps students out of the admin API', function () {
    $profile = actingAsProfessor();
    Sanctum::actingAs($profile->user);

    $this->getJson('/api/v1/admin/professors')->assertForbidden();
});
