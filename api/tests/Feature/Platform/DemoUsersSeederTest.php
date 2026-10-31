<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

it('seeds one test account per role, again without duplicates', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['yekkola.demo_staff_password' => 'demo-mot-2026']);

    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoUsersSeeder::class);

    $demo = User::query()->where('phone_e164', 'like', config('yekkola.demo_phone_prefix').'9%')->get();
    expect($demo)->toHaveCount(5)
        ->and($demo->firstWhere('email', 'demo-finance@yekkola.test')->can('finance.payouts'))->toBeTrue()
        ->and($demo->firstWhere('email', 'demo-moderateur@yekkola.test')->hasRole(Role::Moderator->value))->toBeTrue();

    $this->postJson('/api/v1/auth/staff/sign-in', ['email' => 'demo-admin@yekkola.test', 'password' => 'demo-mot-2026', 'bot_token' => 'ok'], WEB_APP)->assertOk();
});
