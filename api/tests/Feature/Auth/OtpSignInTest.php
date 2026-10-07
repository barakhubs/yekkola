<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

// Request (FR-01, FR-02, FR-10)

it('sends a 6-digit code by SMS and stores only its hash', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '0812345678'], MOBILE_APP)
        ->assertAccepted()
        ->assertJsonStructure(['data' => ['challenge_id', 'expires_at', 'resend_after_seconds']]);

    $code = lastSmsCode('+243812345678');
    $challenge = OtpChallenge::query()->sole();

    expect($code)->toMatch('/^\d{6}$/')
        ->and($challenge->phone_e164)->toBe('+243812345678')
        ->and($challenge->code_hash)->not->toContain($code)
        ->and($challenge->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(4.9);
});

it('rejects invalid phone numbers', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '12'], ['Accept-Language' => 'fr'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'phone.invalid');
});

it('enforces a 60-second resend cooldown', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP)->assertAccepted();

    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP)
        ->assertStatus(429)
        ->assertJsonPath('code', 'otp.cooldown')
        ->assertHeader('Retry-After');

    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP)->assertAccepted();
});

it('allows 3 codes per phone per 10 minutes', function () {
    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP)->assertAccepted();
        $this->travel(61)->seconds();
    }

    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP)
        ->assertStatus(429)
        ->assertJsonPath('code', 'otp.rate_limited');
});

it('allows 10 codes per IP per hour', function () {
    config(['yekkola.auth.otp_global_hourly_budget' => 100]);
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '+2438123400'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)], MOBILE_APP)->assertAccepted();
    }

    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812340099'], MOBILE_APP)
        ->assertStatus(429)
        ->assertJsonPath('code', 'otp.rate_limited');
});

it('requires a passing bot check unless the caller is the mobile app', function (array $headers, ?string $token, int $status) {
    $this->postJson('/api/v1/auth/otp/request', array_filter(['phone' => '+243812345678', 'bot_token' => $token]), $headers)
        ->assertStatus($status);
})->with([
    'web, missing token' => [WEB_APP, null, 422],
    'web, failed token' => [WEB_APP, 'fail', 422],
    'web, valid token' => [WEB_APP, 'ok', 202],
    'script without browser headers or token' => [[], null, 422],
    'script without browser headers, valid token' => [[], 'ok', 202],
    'mobile app' => [MOBILE_APP, null, 202],
]);

it('answers banned numbers like any other, without sending an SMS', function () {
    User::factory()->banned()->create(['phone_e164' => '+243812345678']);

    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP)->assertAccepted();

    expect(app(App\Integrations\Sms\SmsSender::class)->sent())->toBeEmpty();
});

it('only sends codes to supported countries', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+256772123456'], MOBILE_APP)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'phone.country_not_supported');
});

it('pauses all OTP SMS when the global hourly budget is spent', function () {
    config(['yekkola.auth.otp_global_hourly_budget' => 2]);

    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812340001'], MOBILE_APP)->assertAccepted();
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812340002'], MOBILE_APP)->assertAccepted();

    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812340003'], MOBILE_APP)
        ->assertStatus(503)
        ->assertJsonPath('code', 'otp.temporarily_unavailable');
});

it('locks a number after 15 wrong codes in a day, across new codes', function () {
    $payload = ['phone' => '+243812345678', 'device' => mobileDevice()];

    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP)->assertAccepted();
        $wrong = lastSmsCode('+243812345678') === '000000' ? '111111' : '000000';
        foreach (range(1, 5) as $__) {
            $this->postJson('/api/v1/auth/otp/verify', [...$payload, 'code' => $wrong]);
        }
        $this->travel(11)->minutes();
    }

    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP)->assertAccepted();

    $this->postJson('/api/v1/auth/otp/verify', [...$payload, 'code' => lastSmsCode('+243812345678')])
        ->assertStatus(429)
        ->assertJsonPath('code', 'otp.locked');
});

it('keeps the plain code off the queue in clear text', function () {
    $job = new App\Domain\Identity\Jobs\SendOtpSms('+243812345678', '123456', 'fr');

    expect($job)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldBeEncrypted::class)
        ->and($job->tries)->toBe(1)
        ->and(config('horizon.silenced'))->toContain(App\Domain\Identity\Jobs\SendOtpSms::class);
});

// Verify (FR-03, FR-05, FR-06)

it('signs a new mobile user in with a device-bound token', function () {
    $response = signInMobile(extra: [])->assertOk()
        ->assertJsonPath('data.is_new_user', true)
        ->assertJsonPath('data.device.platform', 'android')
        ->assertJsonPath('data.user.phone', '+243812345678');

    $user = userByPhone('+243812345678');
    $token = $response->json('data.token');

    expect($user->hasRole(Role::Student->value))->toBeTrue()
        ->and($user->phone_verified_at)->not->toBeNull()
        ->and($user->tokens()->sole()->device_id)->toBe($response->json('data.device.id'))
        ->and($user->tokens()->sole()->expires_at->isFuture())->toBeTrue();

    freshAuth();
    $this->getJson('/api/v1/me', bearer($token))->assertOk()->assertJsonPath('data.id', $user->id);
});

it('creates the account in the request language', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP);

    $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+243812345678', 'code' => lastSmsCode('+243812345678'), 'device' => mobileDevice(),
    ], ['Accept-Language' => 'en'])->assertOk();

    expect(userByPhone('+243812345678')->locale)->toBe('en');
});

it('signs a web user in with a session', function () {
    $response = signInWeb()->assertOk()->assertJsonMissingPath('data.token');

    $cookie = $response->getCookie(config('session.cookie'), false);

    freshAuth();
    $this->withCookie(config('session.cookie'), $cookie->getValue())
        ->getJson('/api/v1/me', WEB_APP)
        ->assertOk()
        ->assertJsonPath('data.phone', '+243812345678');
});

it('refuses to sign in a client that is neither the web app nor a device', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP);

    $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+243812345678', 'code' => lastSmsCode('+243812345678')])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'auth.client_unknown');
});

it('recognises returning users', function () {
    signInMobile()->assertOk();
    $this->travel(61)->seconds();

    signInMobile()->assertOk()->assertJsonPath('data.is_new_user', false);

    expect(User::query()->count())->toBe(1);
});

it('counts wrong codes and burns the challenge after 5', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP);
    $code = lastSmsCode('+243812345678');
    $wrong = $code === '000000' ? '111111' : '000000';
    $payload = ['phone' => '+243812345678', 'device' => mobileDevice()];

    $this->postJson('/api/v1/auth/otp/verify', [...$payload, 'code' => $wrong])
        ->assertUnprocessable()
        ->assertJson(['code' => 'otp.invalid', 'attempts_remaining' => 4]);

    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/auth/otp/verify', [...$payload, 'code' => $wrong]);
    }

    $this->postJson('/api/v1/auth/otp/verify', [...$payload, 'code' => $wrong])->assertJsonPath('code', 'otp.too_many_attempts');
    $this->postJson('/api/v1/auth/otp/verify', [...$payload, 'code' => $code])->assertJsonPath('code', 'otp.expired');
});

it('rejects expired codes', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP);
    $code = lastSmsCode('+243812345678');

    $this->travel(6)->minutes();

    $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+243812345678', 'code' => $code, 'device' => mobileDevice()])
        ->assertJsonPath('code', 'otp.expired');
});

it('accepts each code only once', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP);
    $code = lastSmsCode('+243812345678');
    $payload = ['phone' => '+243812345678', 'code' => $code, 'device' => mobileDevice()];

    $this->postJson('/api/v1/auth/otp/verify', $payload)->assertOk();
    $this->postJson('/api/v1/auth/otp/verify', $payload)->assertJsonPath('code', 'otp.expired');
});

it('replaces an earlier unused code when a new one is requested', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP);
    $first = lastSmsCode('+243812345678');
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP);
    $second = lastSmsCode('+243812345678');

    if ($first !== $second) {
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+243812345678', 'code' => $first, 'device' => mobileDevice()])
            ->assertJsonPath('code', 'otp.invalid');
    }

    $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+243812345678', 'code' => $second, 'device' => mobileDevice()])->assertOk();
});

// Devices (FR-07, FR-08)

it('treats the same install signing in again as the same device with a fresh token', function () {
    $first = signInMobile()->json('data');
    $this->travel(61)->seconds();
    $second = signInMobile()->json('data');

    expect($second['device']['id'])->toBe($first['device']['id'])
        ->and(Device::query()->count())->toBe(1)
        ->and(userByPhone('+243812345678')->tokens()->count())->toBe(1);

    freshAuth();
    $this->getJson('/api/v1/me', bearer($first['token']))->assertUnauthorized();
});

it('enforces the device limit and lets the user replace a device with the same code', function () {
    $phoneA = signInMobile(installId: 'install-a-000001')->json('data');
    $this->travel(61)->seconds();
    signInMobile(installId: 'install-b-000002')->assertOk();
    $this->travel(61)->seconds();

    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP);
    $code = lastSmsCode('+243812345678');
    $payload = ['phone' => '+243812345678', 'code' => $code, 'device' => mobileDevice('install-c-000003')];

    $this->postJson('/api/v1/auth/otp/verify', $payload)
        ->assertStatus(409)
        ->assertJsonPath('code', 'device.limit_reached')
        ->assertJsonPath('limit', 2)
        ->assertJsonCount(2, 'devices');

    // Same code still works once the user picks a device to sign out.
    $this->postJson('/api/v1/auth/otp/verify', [...$payload, 'replace_device_id' => $phoneA['device']['id']])->assertOk();

    expect(Device::query()->active()->count())->toBe(2)
        ->and(Device::query()->find($phoneA['device']['id'])->revoked_at)->not->toBeNull();

    freshAuth();
    $this->getJson('/api/v1/me', bearer($phoneA['token']))->assertUnauthorized();
});

it('does not let a banned user sign in', function () {
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243812345678'], MOBILE_APP);
    User::factory()->banned()->create(['phone_e164' => '+243812345678']);

    $this->postJson('/api/v1/auth/otp/verify', ['phone' => '+243812345678', 'code' => lastSmsCode('+243812345678'), 'device' => mobileDevice()])
        ->assertForbidden()
        ->assertJsonPath('code', 'account.banned');

    expect(Device::query()->count())->toBe(0);
});

// Logout

it('signs a mobile device out without unregistering it', function () {
    $data = signInMobile()->json('data');

    freshAuth();
    $this->postJson('/api/v1/auth/logout', [], bearer($data['token']))->assertNoContent();

    freshAuth();
    $this->getJson('/api/v1/me', bearer($data['token']))->assertUnauthorized();
    expect(Device::query()->sole()->isActive())->toBeTrue();
});
