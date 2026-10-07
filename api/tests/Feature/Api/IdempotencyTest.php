<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->calls = 0;
    $this->responses = [];

    // Returns queued responses in order (default 201), counting how often the handler really runs.
    Route::middleware(['api', 'idempotent'])->post('/api/v1/_test/orders', function () {
        $this->calls++;
        $next = array_shift($this->responses) ?? 201;

        if ($next === 'nested') {
            // Simulates a retry arriving while the first request is still running.
            $retry = $this->postJson('/api/v1/_test/orders', request()->all(), ['Idempotency-Key' => request()->header('Idempotency-Key')]);

            return response()->json($retry->json(), $retry->status());
        }
        if ($next === 'throw') {
            throw new RuntimeException('gateway timeout');
        }

        return response()->json(['order' => $this->calls], $next, ['Location' => '/api/v1/orders/'.$this->calls]);
    });

    Route::middleware(['api', 'auth:sanctum', 'idempotent'])->post('/api/v1/_test/payments', function () {
        $this->calls++;

        return response()->json(['payment' => $this->calls], 201);
    });
});

it('requires an Idempotency-Key header', function () {
    $this->postJson('/api/v1/_test/orders', ['course' => 'a'])
        ->assertStatus(400)
        ->assertJsonPath('code', 'idempotency.key_missing');

    expect($this->calls)->toBe(0);
});

it('replays the first response, including Location, for the same key and payload', function () {
    $headers = ['Idempotency-Key' => 'key-1'];

    $this->postJson('/api/v1/_test/orders', ['course' => 'a'], $headers)
        ->assertCreated()
        ->assertJson(['order' => 1]);

    $this->postJson('/api/v1/_test/orders', ['course' => 'a'], $headers)
        ->assertCreated()
        ->assertJson(['order' => 1])
        ->assertHeader('Location', '/api/v1/orders/1')
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($this->calls)->toBe(1);
});

it('rejects the same key with a different payload', function () {
    $headers = ['Idempotency-Key' => 'key-2'];

    $this->postJson('/api/v1/_test/orders', ['course' => 'a'], $headers)->assertCreated();

    $this->postJson('/api/v1/_test/orders', ['course' => 'b'], $headers)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'idempotency.key_reused');

    expect($this->calls)->toBe(1);
});

it('treats different keys as different requests', function () {
    $this->postJson('/api/v1/_test/orders', ['course' => 'a'], ['Idempotency-Key' => 'k-a'])->assertJson(['order' => 1]);
    $this->postJson('/api/v1/_test/orders', ['course' => 'a'], ['Idempotency-Key' => 'k-b'])->assertJson(['order' => 2]);

    expect($this->calls)->toBe(2);
});

it('answers in_progress when the same key arrives while the first request runs', function () {
    $this->responses = ['nested'];

    $this->postJson('/api/v1/_test/orders', ['course' => 'a'], ['Idempotency-Key' => 'k-race'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency.in_progress');

    expect($this->calls)->toBe(1);
});

it('does not store temporary failures, so the retry runs again', function (int|string $first) {
    $this->responses = [$first, 201];
    $headers = ['Idempotency-Key' => 'k-retry'];

    $this->postJson('/api/v1/_test/orders', ['course' => 'a'], $headers);
    $this->postJson('/api/v1/_test/orders', ['course' => 'a'], $headers)
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect($this->calls)->toBe(2);
})->with([
    'rate limited' => 429,
    'conflict' => 409,
    'exception (500)' => 'throw',
]);

it('scopes keys to the token user, not the IP', function () {
    $user = User::factory()->create();
    $token = $user->createToken('android')->plainTextToken;
    $headers = ['Idempotency-Key' => 'k-user', 'Authorization' => 'Bearer '.$token];

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->postJson('/api/v1/_test/payments', ['amount' => 5], $headers)->assertCreated();

    // Same user switches from Wi-Fi to mobile data: new IP, same key → replay, no second payment.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/api/v1/_test/payments', ['amount' => 5], $headers)
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($this->calls)->toBe(1);
});

it('keeps different users with the same key apart', function () {
    foreach (User::factory()->count(2)->create() as $user) {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/_test/payments', ['amount' => 5], [
            'Idempotency-Key' => 'same-key',
            'Authorization' => 'Bearer '.$user->createToken('android')->plainTextToken,
        ])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    }

    expect($this->calls)->toBe(2);
});
