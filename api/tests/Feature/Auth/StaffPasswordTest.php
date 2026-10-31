<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

function staffSignIn(string $email, string $password): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/auth/staff/sign-in', ['email' => $email, 'password' => $password, 'bot_token' => 'ok'], WEB_APP);
}

it('creates the default super admin from its settings, who must then choose a password', function () {
    test()->seed(RolesAndPermissionsSeeder::class);
    config(['yekkola.super_admin' => ['email' => 'Admin@Yekkola.test', 'password' => 'premier-mot-2026', 'phone' => '0812000999', 'name' => 'Super admin']]);

    $this->artisan('admin:create-super-admin')->assertSuccessful();
    $this->artisan('admin:create-super-admin', ['--password' => 'court'])->assertFailed(); // too weak

    $admin = User::query()->where('email', 'admin@yekkola.test')->sole();
    expect($admin->hasRole(Role::SuperAdmin->value))->toBeTrue()
        ->and($admin->phone_e164)->toBe('+243812000999')
        ->and($admin->must_change_password)->toBeTrue()
        ->and(Hash::check('premier-mot-2026', (string) $admin->password))->toBeTrue();

    staffSignIn('admin@yekkola.test', 'premier-mot-2026')->assertOk()
        ->assertJsonPath('data.must_change_password', true)
        ->assertJsonPath('data.has_password', true);
    $this->assertAuthenticatedAs($admin, 'web');

    // Until they choose their own, the back office is closed; /me stays open.
    $this->getJson('/api/v1/admin/dashboard', WEB_APP)->assertForbidden()->assertJsonPath('code', 'auth.password_change_required');
    $this->getJson('/api/v1/me', WEB_APP)->assertOk();

    $this->putJson('/api/v1/me/password', ['current_password' => 'faux', 'password' => 'nouveau-mot-2026', 'password_confirmation' => 'nouveau-mot-2026'], WEB_APP)
        ->assertUnprocessable()->assertJsonPath('code', 'auth.current_password_wrong');
    $this->putJson('/api/v1/me/password', ['current_password' => 'premier-mot-2026', 'password' => 'court', 'password_confirmation' => 'court'], WEB_APP)
        ->assertUnprocessable();
    $this->putJson('/api/v1/me/password', ['current_password' => 'premier-mot-2026', 'password' => 'nouveau-mot-2026', 'password_confirmation' => 'nouveau-mot-2026'], WEB_APP)
        ->assertOk()->assertJsonPath('data.must_change_password', false);
    $this->getJson('/api/v1/admin/dashboard', WEB_APP)->assertOk();
});

it('answers every failure the same way: unknown email, wrong password, not staff, banned', function () {
    test()->seed(RolesAndPermissionsSeeder::class);
    $staff = User::factory()->create(['email' => 'staff@yekkola.test', 'password' => Hash::make('bon-mot-de-passe-1')]);
    $staff->assignRole(Role::Moderator->value);
    $student = User::factory()->create(['email' => 'eleve@yekkola.test', 'password' => Hash::make('bon-mot-de-passe-1')]);
    $student->assignRole(Role::Student->value);

    foreach ([['nobody@yekkola.test', 'bon-mot-de-passe-1'], ['staff@yekkola.test', 'mauvais'], ['eleve@yekkola.test', 'bon-mot-de-passe-1']] as [$email, $password]) {
        staffSignIn($email, $password)->assertUnprocessable()->assertJsonPath('code', 'auth.invalid_credentials');
    }
    $staff->forceFill(['status' => UserStatus::Banned])->save();
    staffSignIn('staff@yekkola.test', 'bon-mot-de-passe-1')->assertUnprocessable()->assertJsonPath('code', 'auth.invalid_credentials');

    $this->postJson('/api/v1/auth/staff/sign-in', ['email' => 'staff@yekkola.test', 'password' => 'x', 'bot_token' => 'fail'], WEB_APP)->assertUnprocessable();
    $this->postJson('/api/v1/auth/staff/sign-in', ['email' => 'staff@yekkola.test', 'password' => 'x'], MOBILE_APP)->assertUnprocessable(); // browsers only
    $this->assertGuest('web');
});

it('slows down guessing', function () {
    foreach (range(1, 5) as $_) {
        staffSignIn('staff@yekkola.test', 'mauvais')->assertUnprocessable();
    }
    staffSignIn('staff@yekkola.test', 'mauvais')->assertTooManyRequests();
});

it('lets a staff manager set a staff member\'s password, never a student\'s', function () {
    $admin = actingAsStaff(Role::Admin);
    $moderator = User::factory()->create(['email' => 'mod@yekkola.test']);
    $moderator->assignRole(Role::Moderator->value);
    $moderator->createToken('app');
    $student = User::factory()->create();
    Sanctum::actingAs($admin);

    $this->putJson("/api/v1/admin/staff/{$moderator->id}/password", ['password' => 'temporaire-2026'])->assertOk();
    expect($moderator->refresh()->must_change_password)->toBeTrue()
        ->and($moderator->tokens()->count())->toBe(0);
    staffSignIn('mod@yekkola.test', 'temporaire-2026')->assertOk();

    Sanctum::actingAs($admin);
    $this->putJson("/api/v1/admin/staff/{$student->id}/password", ['password' => 'temporaire-2026'])->assertUnprocessable();
    $this->putJson("/api/v1/admin/staff/{$admin->id}/password", ['password' => 'temporaire-2026'])->assertForbidden();
});
