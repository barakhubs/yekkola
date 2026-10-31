<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Activity;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    test()->seed(RolesAndPermissionsSeeder::class);
    $this->super = User::factory()->create(['name' => 'Chef', 'email' => 'chef@yekkola.test', 'password' => Hash::make('mot-de-passe-2026')]);
    $this->super->assignRole(Role::SuperAdmin->value);
    $this->student = User::factory()->create(['name' => 'Grâce Mbuyi']);
    $this->student->assignRole(Role::Student->value);

    $this->postJson('/api/v1/auth/staff/sign-in', ['email' => 'chef@yekkola.test', 'password' => 'mot-de-passe-2026', 'bot_token' => 'ok'], WEB_APP)->assertOk();
});

/** A real request starts with fresh guards; requests inside one test share them. */
function switched(): void
{
    app('auth')->forgetGuards();
}

it('lets a super admin view the platform as a student, within limits, then come back', function () {
    $this->getJson("/api/v1/admin/users/{$this->student->id}", WEB_APP)->assertJsonPath('data.can.impersonate', true);

    $this->postJson("/api/v1/admin/users/{$this->student->id}/impersonate", [], WEB_APP)->assertOk()
        ->assertJsonPath('data.home', 'web')
        ->assertJsonPath('data.user.id', $this->student->id);
    switched();

    $this->getJson('/api/v1/me', WEB_APP)->assertOk()
        ->assertJsonPath('data.id', $this->student->id)
        ->assertJsonPath('data.impersonation.by_name', 'Chef');
    $this->getJson('/api/v1/me/enrollments', WEB_APP)->assertOk(); // sees what they see
    $this->postJson('/api/v1/orders', ['course_ids' => ['01JZZZZZZZZZZZZZZZZZZZZZZZ']], WEB_APP + ['Idempotency-Key' => 'x'])->assertForbidden()
        ->assertJsonPath('code', 'auth.impersonation_forbidden');
    $this->patchJson('/api/v1/me', ['name' => 'Autre'], WEB_APP)->assertForbidden();
    $this->getJson('/api/v1/admin/dashboard', WEB_APP)->assertForbidden(); // a student has no back office

    $this->postJson('/api/v1/me/impersonation/stop', [], WEB_APP)->assertOk()->assertJsonPath('data.user.id', $this->super->id)
        ->assertJsonPath('data.redirect_to', 'http://localhost:3001/users');
    switched();
    $this->getJson('/api/v1/me', WEB_APP)->assertJsonPath('data.id', $this->super->id)->assertJsonPath('data.impersonation', null);

    $started = Activity::query()->where('event', 'impersonation.started')->sole();
    expect($started->causer_id)->toBe($this->super->id)
        ->and($started->subject_id)->toBe($this->student->id)
        ->and(Activity::query()->where('event', 'impersonation.ended')->sole()->properties['reason'])->toBe('stopped');
});

it('opens the back office as a staff member sees it, account actions refused, entries naming the super admin', function () {
    $admin = User::factory()->create(['name' => 'Admin Kasongo']);
    $admin->assignRole(Role::Admin->value);
    $target = User::factory()->create();

    $this->postJson("/api/v1/admin/users/{$admin->id}/impersonate", [], WEB_APP)->assertOk()->assertJsonPath('data.home', 'admin');
    switched();
    $this->getJson('/api/v1/admin/dashboard', WEB_APP)->assertOk(); // their back office
    $this->postJson("/api/v1/admin/users/{$target->id}/sign-out", [], WEB_APP)->assertForbidden()
        ->assertJsonPath('code', 'auth.impersonation_forbidden');

    // An audited action they may take (a category) records who really did it.
    $this->postJson('/api/v1/admin/categories', ['name' => ['fr' => 'Essai', 'en' => 'Test'], 'is_visible' => false], WEB_APP)->assertCreated();
    $entry = Activity::query()->where('event', 'category.created')->sole();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->properties['context']['impersonated_by'])->toBe($this->super->id);
});

it('ends by itself after an hour, back on the super admin\'s account', function () {
    $this->postJson("/api/v1/admin/users/{$this->student->id}/impersonate", [], WEB_APP)->assertOk();
    switched();
    $this->travel(61)->minutes();

    $this->getJson('/api/v1/me/enrollments', WEB_APP)->assertStatus(409)->assertJsonPath('code', 'auth.impersonation_ended');
    switched();
    $this->getJson('/api/v1/me', WEB_APP)->assertJsonPath('data.id', $this->super->id);
    expect(Activity::query()->where('event', 'impersonation.ended')->sole()->properties['reason'])->toBe('expired');
});

it('is for super admins, never on another super admin or from a nested session', function () {
    $other = User::factory()->create();
    $other->assignRole(Role::SuperAdmin->value);
    $this->postJson("/api/v1/admin/users/{$other->id}/impersonate", [], WEB_APP)->assertForbidden()
        ->assertJsonPath('code', 'auth.impersonation_not_allowed');
    $this->postJson("/api/v1/admin/users/{$this->super->id}/impersonate", [], WEB_APP)->assertForbidden();

    $admin = actingAsStaff(Role::Admin);
    Sanctum::actingAs($admin);
    $this->postJson("/api/v1/admin/users/{$this->student->id}/impersonate")->assertForbidden(); // not a super admin (and no session)
});
