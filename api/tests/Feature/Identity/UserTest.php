<?php

declare(strict_types=1);

use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('creates users with ULID ids and DRC phone numbers', function () {
    $user = User::factory()->create();

    expect(Str::isUlid($user->id))->toBeTrue()
        ->and($user->phone_e164)->toStartWith('+243')
        ->and($user->isActive())->toBeTrue();
});

it('never serialises the phone number or email by default', function () {
    $user = User::factory()->withEmail()->create();

    expect($user->toArray())->not->toHaveKeys(['phone_e164', 'email']);
});

it('issues Sanctum tokens with non-enumerable ULID ids that authenticate', function () {
    Route::middleware(['api', 'auth:sanctum'])->get('/api/v1/_test/me', fn () => ['id' => request()->user()->id]);

    $user = User::factory()->create();
    $plain = $user->createToken('android')->plainTextToken;
    [$tokenId] = explode('|', $plain, 2);

    expect(Str::isUlid($tokenId))->toBeTrue()
        ->and(PersonalAccessToken::findToken($plain)?->tokenable->is($user))->toBeTrue();

    $this->getJson('/api/v1/_test/me', ['Authorization' => 'Bearer '.$plain])
        ->assertOk()
        ->assertJson(['id' => $user->id]);
});
