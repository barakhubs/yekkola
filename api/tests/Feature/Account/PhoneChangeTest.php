<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;

it('moves the account to a new number and signs out other devices', function () {
    $current = signInMobile(installId: 'install-a-000001')->json('data');
    $this->travel(61)->seconds();
    $other = signInMobile(installId: 'install-b-000002')->json('data');

    freshAuth();
    $this->postJson('/api/v1/me/phone/otp', ['phone' => '0972345678'], bearer($current['token']))->assertAccepted();

    freshAuth();
    $this->putJson('/api/v1/me/phone', ['phone' => '+243972345678', 'code' => lastSmsCode('+243972345678')], bearer($current['token']))
        ->assertOk()
        ->assertJsonPath('data.phone', '+243972345678');

    freshAuth();
    $this->getJson('/api/v1/me', bearer($current['token']))->assertOk();
    freshAuth();
    $this->getJson('/api/v1/me', bearer($other['token']))->assertUnauthorized();

    expect(User::query()->where('phone_e164', '+243812345678')->exists())->toBeFalse();
});

it('keeps the current web session after a phone change', function () {
    $cookie = signInWeb()->getCookie(config('session.cookie'), false)->getValue();

    freshAuth();
    $this->withCookie(config('session.cookie'), $cookie)
        ->postJson('/api/v1/me/phone/otp', ['phone' => '+243972345678'], WEB_APP)->assertAccepted();

    freshAuth();
    $this->withCookie(config('session.cookie'), $cookie)
        ->putJson('/api/v1/me/phone', ['phone' => '+243972345678', 'code' => lastSmsCode('+243972345678')], WEB_APP)
        ->assertOk();

    freshAuth();
    $this->withCookie(config('session.cookie'), $cookie)->getJson('/api/v1/me', WEB_APP)->assertOk();
});

it('does not reveal whether a number is taken: same answer, no SMS', function () {
    User::factory()->create(['phone_e164' => '+243972345678']);
    $token = signInMobile()->json('data.token');

    freshAuth();
    $this->postJson('/api/v1/me/phone/otp', ['phone' => '+243972345678'], bearer($token))->assertAccepted();

    $sent = collect(app(App\Integrations\Sms\SmsSender::class)->sent())->pluck('to');
    expect($sent)->not->toContain('+243972345678');
});

it('says when the new number is already the current one', function () {
    $token = signInMobile()->json('data.token');

    freshAuth();
    $this->postJson('/api/v1/me/phone/otp', ['phone' => '+243812345678'], bearer($token))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'phone.unchanged');
});

it('requires a recent sign-in to change the phone', function () {
    $token = signInMobile()->json('data.token');
    $this->travel(16)->minutes();

    freshAuth();
    $this->postJson('/api/v1/me/phone/otp', ['phone' => '+243972345678'], bearer($token))
        ->assertForbidden()
        ->assertJsonPath('code', 'auth.reauthentication_required');
});

it('notifies the old number, audits the change, and revokes other devices', function () {
    $current = signInMobile(installId: 'install-a-000001')->json('data');
    $this->travel(61)->seconds();
    $other = signInMobile(installId: 'install-b-000002')->json('data');

    freshAuth();
    $this->postJson('/api/v1/me/phone/otp', ['phone' => '+243972345678'], bearer($current['token']))->assertAccepted();
    freshAuth();
    $this->putJson('/api/v1/me/phone', ['phone' => '+243972345678', 'code' => lastSmsCode('+243972345678')], bearer($current['token']))->assertOk();

    $notice = collect(app(App\Integrations\Sms\SmsSender::class)->sent())->where('to', '+243812345678')->last();
    $audit = App\Domain\Platform\Models\Activity::query()->where('event', 'user.phone_changed')->sole();

    expect($notice['message'])->toContain('+243 9** *** 678')
        ->and($audit->properties->get('before'))->toBe('+243 8•• ••• 678')
        ->and(App\Domain\Identity\Models\Device::query()->find($other['device']['id'])->isActive())->toBeFalse()
        ->and(App\Domain\Identity\Models\Device::query()->find($current['device']['id'])->isActive())->toBeTrue();
});

it('does not accept a sign-in code to change the phone', function () {
    $token = signInMobile()->json('data.token');
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/otp/request', ['phone' => '+243972345678'], MOBILE_APP);

    freshAuth();
    $this->putJson('/api/v1/me/phone', ['phone' => '+243972345678', 'code' => lastSmsCode('+243972345678')], bearer($token))
        ->assertJsonPath('code', 'otp.expired');
});
