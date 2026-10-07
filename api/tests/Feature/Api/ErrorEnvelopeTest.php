<?php

declare(strict_types=1);

use App\Domain\Shared\Exceptions\InvalidPhoneNumber;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/_test')->group(function () {
        Route::post('/validate', fn () => request()->validate(['phone' => ['required', 'string']]));
        Route::get('/domain-error', fn () => throw InvalidPhoneNumber::make());
        Route::get('/crash', fn () => throw new RuntimeException('boom'));
        Route::get('/private', fn () => 'secret')->middleware('auth:sanctum');
    });
});

// Note: Laravel's test client sends `Accept-Language: en-us` unless a header is given, so tests set it explicitly.

it('returns the error envelope for unknown routes', function () {
    $this->getJson('/api/v1/does-not-exist', ['Accept-Language' => 'fr'])
        ->assertNotFound()
        ->assertJson([
            'type' => 'about:blank',
            'status' => 404,
            'code' => 'resource.not_found',
            'title' => 'Ressource introuvable.',
        ]);
});

it('answers in French or English from Accept-Language, falling back to French', function () {
    $this->getJson('/api/v1/does-not-exist', ['Accept-Language' => 'fr-CD,fr;q=0.9'])
        ->assertHeader('Content-Language', 'fr')
        ->assertJsonPath('title', 'Ressource introuvable.');

    $this->getJson('/api/v1/does-not-exist', ['Accept-Language' => 'sw'])
        ->assertHeader('Content-Language', 'fr');

    $this->getJson('/api/v1/does-not-exist', ['Accept-Language' => 'en-GB,en;q=0.9'])
        ->assertHeader('Content-Language', 'en')
        ->assertJsonPath('title', 'Not found.');
});

it('returns field errors for validation failures in the request language', function () {
    $this->postJson('/api/v1/_test/validate', [], ['Accept-Language' => 'fr'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation.failed')
        ->assertJsonStructure(['errors' => ['phone']]);
});

it('maps domain exceptions to their stable code and status', function () {
    $this->getJson('/api/v1/_test/domain-error', ['Accept-Language' => 'en'])
        ->assertUnprocessable()
        ->assertJson(['code' => 'phone.invalid', 'title' => 'Invalid phone number.']);
});

it('returns 401 with a code for unauthenticated requests', function () {
    $this->getJson('/api/v1/_test/private')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'auth.unauthenticated');
});

it('hides exception details on server errors when debug is off', function () {
    config(['app.debug' => false]);

    $this->getJson('/api/v1/_test/crash')
        ->assertStatus(500)
        ->assertJsonPath('code', 'server.error')
        ->assertJsonMissingPath('detail');
});
