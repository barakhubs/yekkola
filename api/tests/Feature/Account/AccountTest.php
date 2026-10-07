<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\SignOutEverywhere;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Events\DeviceRevoked;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Province;
use Database\Seeders\ProvinceSeeder;
use Illuminate\Support\Facades\Event;

// Profile (FR-04)

it('returns and updates the profile', function () {
    $this->seed(ProvinceSeeder::class);
    $token = signInMobile()->json('data.token');
    $province = Province::query()->where('code', 'CD-SK')->sole();

    freshAuth();
    $this->patchJson('/api/v1/me', [
        'name' => 'Amani Byamungu',
        'email' => 'amani@example.cd',
        'locale' => 'en',
        'province_id' => $province->id,
        'city' => 'Bukavu',
    ], bearer($token))
        ->assertOk()
        ->assertJsonPath('data.name', 'Amani Byamungu')
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.province.name', 'Sud-Kivu')
        ->assertJsonPath('data.locale', 'en');
});

it('validates profile fields', function (array $payload, string $field) {
    $token = signInMobile()->json('data.token');

    freshAuth();
    $this->patchJson('/api/v1/me', $payload, bearer($token))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation.failed')
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'unsupported language' => [['locale' => 'sw'], 'locale'],
    'name too short' => [['name' => 'A'], 'name'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'unknown province' => [['province_id' => '01JA0000000000000000000000'], 'province_id'],
]);

// Suspension and bans (FR-15)

it('lets a suspended user read their profile but nothing else', function () {
    $token = signInMobile()->json('data.token');
    userByPhone('+243812345678')->forceFill(['status' => UserStatus::Suspended])->save();

    freshAuth();
    $this->getJson('/api/v1/me', bearer($token))->assertOk()->assertJsonPath('data.status', 'suspended');

    freshAuth();
    $this->patchJson('/api/v1/me', ['name' => 'Nouveau'], bearer($token))
        ->assertForbidden()
        ->assertJsonPath('code', 'account.suspended');
});

it('signs banned users out and refuses them', function () {
    $token = signInMobile()->json('data.token');
    userByPhone('+243812345678')->forceFill(['status' => UserStatus::Banned])->save();

    freshAuth();
    $this->getJson('/api/v1/me', bearer($token))->assertForbidden()->assertJsonPath('code', 'account.banned');

    expect(userByPhone('+243812345678')->tokens()->count())->toBe(0);
});

it('ends web sessions after "sign out everywhere"', function () {
    $cookie = signInWeb()->getCookie(config('session.cookie'), false)->getValue();
    app(SignOutEverywhere::class)->handle(userByPhone('+243812345678'));

    freshAuth();
    $this->withCookie(config('session.cookie'), $cookie)
        ->getJson('/api/v1/me', WEB_APP)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'auth.session_expired');
});

// Devices (FR-07)

it('lists active devices and marks the current one', function () {
    $first = signInMobile(installId: 'install-a-000001')->json('data');
    $this->travel(61)->seconds();
    signInMobile(installId: 'install-b-000002');

    freshAuth();
    $devices = $this->getJson('/api/v1/me/devices', bearer($first['token']))->assertOk()->json('data');

    expect($devices)->toHaveCount(2)
        ->and(collect($devices)->firstWhere('id', $first['device']['id'])['is_current'])->toBeTrue()
        ->and($devices[0])->not->toHaveKey('install_id');
});

it('removes a device, revoking its token', function () {
    Event::fake([DeviceRevoked::class]);
    $first = signInMobile(installId: 'install-a-000001')->json('data');
    $this->travel(61)->seconds();
    $second = signInMobile(installId: 'install-b-000002')->json('data');

    freshAuth();
    $this->deleteJson("/api/v1/me/devices/{$first['device']['id']}", [], bearer($second['token']))->assertNoContent();

    freshAuth();
    $this->getJson('/api/v1/me', bearer($first['token']))->assertUnauthorized();
    Event::assertDispatched(DeviceRevoked::class, fn (DeviceRevoked $e) => $e->device->id === $first['device']['id']);
});

it("cannot remove someone else's device", function () {
    $mine = signInMobile('+243812345678')->json('data');
    $this->travel(61)->seconds();
    $theirs = signInMobile('+243972345678', 'install-other-01')->json('data');

    freshAuth();
    $this->deleteJson("/api/v1/me/devices/{$theirs['device']['id']}", [], bearer($mine['token']))
        ->assertNotFound()
        ->assertJsonPath('code', 'device.not_found');

    expect(Device::query()->find($theirs['device']['id'])->isActive())->toBeTrue();
});

// Provinces (public)

it('lists the provinces for the profile form', function () {
    $this->seed(ProvinceSeeder::class);

    $this->getJson('/api/v1/provinces')->assertOk()->assertJsonCount(26, 'data');
});

it('keeps the user model free of raw phone strings in domain code', function () {
    $user = User::factory()->create(['phone_e164' => '+243812345678']);

    expect($user->phone->masked())->toBe('+243 8•• ••• 678');
});
