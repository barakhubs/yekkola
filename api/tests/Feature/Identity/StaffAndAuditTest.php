<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->admin = actingAsStaff(Role::Admin);
});

it('invites staff by phone, adds finance on top of the role, then ends their access', function () {
    $id = $this->postJson('/api/v1/admin/staff', ['phone' => '0812000222', 'role' => 'moderator', 'permissions' => ['finance.view']])
        ->assertCreated()
        ->assertJsonPath('data.role', 'moderator')
        ->assertJsonPath('data.extra_permissions', ['finance.view'])
        ->json('data.id');
    $staff = User::query()->findOrFail($id);
    expect($staff->phone_e164)->toBe('+243812000222');

    Sanctum::actingAs($staff);
    $this->getJson('/api/v1/admin/ledger/accounts')->assertOk(); // the extra permission works
    $this->getJson('/api/v1/admin/settings')->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/admin/staff')->assertOk()->assertJsonPath('data', fn (array $rows) => collect($rows)->contains('id', $id));
    $this->putJson("/api/v1/admin/staff/{$id}", ['role' => 'admin', 'permissions' => []])->assertOk()->assertJsonPath('data.role', 'admin');

    $staff->createToken('app');
    $this->deleteJson("/api/v1/admin/staff/{$id}")->assertOk()->assertJsonPath('data.role', null)->assertJsonPath('data.permissions', []);
    expect($staff->refresh()->tokens()->count())->toBe(0)
        ->and($staff->hasRole(Role::Student->value))->toBeTrue(); // still a student

    $events = collect($this->getJson('/api/v1/admin/audit-log?filter[event]=staff')->assertOk()->json('data'))->pluck('event');
    expect($events->all())->toBe(['staff.removed', 'staff.updated', 'staff.updated'])
        ->and($this->getJson('/api/v1/admin/audit-log?filter[subject_id]='.$id)->json('data.0.causer.id'))->toBe($this->admin->id);
});

it('never lets staff change their own access, or a super admin\'s without staff.roles', function () {
    $this->putJson("/api/v1/admin/staff/{$this->admin->id}", ['role' => 'moderator', 'permissions' => []])->assertForbidden()
        ->assertJsonPath('code', 'user.own_status');
    $this->postJson('/api/v1/admin/staff', ['phone' => '0812000333', 'role' => 'super-admin', 'permissions' => []])->assertForbidden()
        ->assertJsonPath('code', 'user.staff_protected');

    $super = actingAsStaff(Role::SuperAdmin);
    Sanctum::actingAs($this->admin);
    $this->deleteJson("/api/v1/admin/staff/{$super->id}")->assertForbidden();
    $this->postJson('/api/v1/admin/staff', ['phone' => '0812000444', 'role' => 'student', 'permissions' => []])->assertUnprocessable();
});

it('shows the audit log only to those who may read it', function () {
    Sanctum::actingAs(actingAsStaff(Role::Moderator));

    $this->getJson('/api/v1/admin/audit-log')->assertForbidden();
    $this->getJson('/api/v1/admin/staff')->assertForbidden();
});
