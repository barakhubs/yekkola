<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/** @return list<string> */
function throttles(Route $route): array
{
    return array_values(array_filter(Router::gatherRouteMiddleware($route), fn ($m) => is_string($m) && str_contains($m, 'ThrottleRequests')));
}

/** Uses up a baseline (`api` limiter) bucket. */
function exhaust(string $key, int $max): void
{
    foreach (range(1, $max) as $i) {
        app(RateLimiter::class)->hit(md5('api'.$key), 60);
    }
}

it('gives sensitive routes their own limit on top of the baseline', function () {
    $sensitive = [
        'me.destroy' => 'account-deletion', 'me.deletion.cancel' => 'account-deletion', 'me.export.download' => 'exports',
        'professor.ledger.csv' => 'exports', 'admin.kyc-submissions.file' => 'sensitive-files',
        'admin.professor-applications.credential' => 'sensitive-files', 'admin.staff.store' => 'staff-admin',
        'admin.staff.password' => 'staff-admin', 'admin.users.impersonate' => 'impersonation',
        'auth.otp.verify' => 'otp-verify', 'auth.staff.sign-in' => 'staff-sign-in', 'orders.pay' => 'payments',
    ];
    foreach ($sensitive as $name => $limiter) {
        $route = Router::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route {$name}")
            ->and(throttles($route))->toContain(ThrottleRequests::class.':api')
            ->and(collect(throttles($route))->contains(fn (string $m) => str_ends_with($m, ':'.$limiter)))->toBeTrue("{$name} → {$limiter}");
    }
});

it('limits a signed-in account by account, with a real bearer token, also on public routes', function () {
    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;
    exhaust('user:'.$user->id, 600);

    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited')
        ->assertHeader('Retry-After');
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/categories')->assertStatus(429);

    // Another account on the same network is unaffected.
    app('auth')->forgetGuards();
    $other = User::factory()->create()->createToken('phone')->plainTextToken;
    $this->withToken($other)->getJson('/api/v1/categories')->assertOk();
});

it('limits visitors by IP and ignores a forwarded IP without the web server key', function () {
    exhaust('127.0.0.1', 1_500);

    $this->getJson('/api/v1/categories', ['X-Client-Ip' => '10.1.2.3'])->assertStatus(429);

    config(['yekkola.web_server_key' => 'server-key']);
    $this->getJson('/api/v1/categories', ['X-Web-Server-Key' => 'server-key', 'X-Client-Ip' => '10.1.2.3'])->assertOk();
});
