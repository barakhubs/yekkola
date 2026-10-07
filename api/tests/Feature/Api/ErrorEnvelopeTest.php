<?php

declare(strict_types=1);

use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\InvalidPhoneNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/_test')->group(function () {
        Route::post('/validate', fn () => request()->validate(['phone' => ['required', 'string']]));
        Route::get('/domain-error', fn () => throw InvalidPhoneNumber::make());
        Route::get('/crash', fn () => throw new RuntimeException('boom'));
        Route::get('/private', fn () => 'secret')->middleware('auth:sanctum');
        Route::get('/forbidden', fn () => throw new AuthorizationException);
        Route::get('/denied-as-404', fn () => AccessResponse::denyAsNotFound()->authorize());
        Route::get('/csrf', fn () => abort(419));
        Route::get('/throttled', fn () => 'ok')->middleware('throttle:1,1');
        Route::get('/thrown-response', fn () => throw new HttpResponseException(response()->json(['custom' => true], 409)));
        Route::get('/context-clash', fn () => throw new class extends DomainException
        {
            public function __construct()
            {
                parent::__construct('phone.invalid', 422, ['status' => 'pending', 'code' => 'x', 'payment_state' => 'pending']);
            }
        });
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

it('maps HTTP errors to stable codes', function (string $method, string $uri, int $status, string $code) {
    $this->json($method, $uri, [], ['Accept-Language' => 'en'])
        ->assertStatus($status)
        ->assertJsonPath('code', $code);
})->with([
    'forbidden' => ['GET', '/api/v1/_test/forbidden', 403, 'auth.forbidden'],
    'policy denied as not found' => ['GET', '/api/v1/_test/denied-as-404', 404, 'resource.not_found'],
    'method not allowed' => ['DELETE', '/api/v1/ping', 405, 'http.method_not_allowed'],
    'csrf expired' => ['GET', '/api/v1/_test/csrf', 419, 'auth.csrf_mismatch'],
]);

it('returns 429 with Retry-After when rate limited', function () {
    $this->getJson('/api/v1/_test/throttled')->assertOk();

    $this->getJson('/api/v1/_test/throttled')
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited')
        ->assertHeader('Retry-After');
});

it('returns deliberately thrown responses unchanged', function () {
    $this->getJson('/api/v1/_test/thrown-response')
        ->assertStatus(409)
        ->assertExactJson(['custom' => true]);
});

it('never lets domain context override envelope fields', function () {
    $this->getJson('/api/v1/_test/context-clash', ['Accept-Language' => 'en'])
        ->assertStatus(422)
        ->assertJson(['status' => 422, 'code' => 'phone.invalid', 'payment_state' => 'pending']);
});

it('marks responses as varying by language', function () {
    $response = $this->getJson('/api/v1/ping', ['Accept-Language' => 'en']);

    expect($response->baseResponse->getVary())->toContain('Accept-Language');
});

it('hides exception details on server errors when debug is off', function () {
    config(['app.debug' => false]);

    $this->getJson('/api/v1/_test/crash')
        ->assertStatus(500)
        ->assertJsonPath('code', 'server.error')
        ->assertJsonMissingPath('detail');
});
