<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->calls = 0;

    Route::middleware(['api', 'idempotent'])->post('/api/v1/_test/orders', function () {
        $this->calls++;

        return response()->json(['order' => $this->calls], 201);
    });
});

it('requires an Idempotency-Key header', function () {
    $this->postJson('/api/v1/_test/orders', ['course' => 'a'])
        ->assertStatus(400)
        ->assertJsonPath('code', 'idempotency.key_missing');

    expect($this->calls)->toBe(0);
});

it('replays the first response for the same key and payload', function () {
    $headers = ['Idempotency-Key' => 'key-1'];

    $this->postJson('/api/v1/_test/orders', ['course' => 'a'], $headers)
        ->assertCreated()
        ->assertJson(['order' => 1]);

    $this->postJson('/api/v1/_test/orders', ['course' => 'a'], $headers)
        ->assertCreated()
        ->assertJson(['order' => 1])
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
