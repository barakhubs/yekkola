<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\DevicePlatform;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Activity;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->admin = actingAsStaff(Role::Admin); // seeds the roles
    $this->student = User::factory()->create(['name' => 'Grâce Mbuyi', 'phone_e164' => '+243812000111']);
    $this->student->assignRole(Role::Student->value);
});

it('finds accounts by phone or name, with the number masked', function () {
    User::factory()->create(['name' => 'Jean Ilunga']);

    $this->getJson('/api/v1/admin/users?filter[q]=0812000111')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Grâce Mbuyi')
        ->assertJsonPath('data.0.phone', fn (string $p) => ! str_contains($p, '000111'));
    $this->getJson('/api/v1/admin/users?filter[q]=mbuy')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/users/'.$this->student->id)->assertOk()
        ->assertJsonPath('data.can', ['suspend' => true, 'ban' => true, 'reactivate' => false, 'sign_out' => true, 'remove_device' => true]);
});

it('suspends with a reason, signing the account out everywhere, then reactivates', function () {
    $this->student->createToken('app');
    $epoch = $this->student->auth_epoch;

    $this->postJson("/api/v1/admin/users/{$this->student->id}/status", ['status' => 'suspended', 'note' => 'Fraude au paiement'])->assertOk()
        ->assertJsonPath('data.status', 'suspended')
        ->assertJsonPath('data.can.reactivate', true);

    $student = $this->student->refresh();
    expect($student->status)->toBe(UserStatus::Suspended)
        ->and($student->tokens()->count())->toBe(0)
        ->and($student->auth_epoch)->toBe($epoch + 1)
        ->and(Activity::query()->where('event', 'user.status_changed')->sole()->properties['note'])->toBe('Fraude au paiement');

    $this->postJson("/api/v1/admin/users/{$this->student->id}/status", ['status' => 'active', 'note' => 'Vérifié'])->assertOk()
        ->assertJsonPath('data.status', 'active');
    $this->postJson("/api/v1/admin/users/{$this->student->id}/status", ['status' => 'banned'])->assertUnprocessable(); // reason required
});

it('removes a device and signs out everywhere', function () {
    $device = Device::query()->create(['user_id' => $this->student->id, 'platform' => DevicePlatform::Android, 'install_id' => 'inst-1', 'registered_at' => now()]);

    $this->getJson('/api/v1/admin/users/'.$this->student->id)->assertJsonCount(1, 'data.devices');
    $this->deleteJson("/api/v1/admin/users/{$this->student->id}/devices/{$device->id}")->assertOk()->assertJsonCount(0, 'data.devices');
    $this->postJson("/api/v1/admin/users/{$this->student->id}/sign-out")->assertOk();

    expect(Activity::query()->whereIn('event', ['user.device_removed', 'user.signed_out_everywhere'])->count())->toBe(2);
});

it('protects staff accounts and one\'s own, and needs users.manage to act', function () {
    $this->postJson("/api/v1/admin/users/{$this->admin->id}/status", ['status' => 'suspended', 'note' => 'Test'])->assertForbidden()
        ->assertJsonPath('code', 'user.own_status');

    $moderator = actingAsStaff(Role::Moderator);
    // Support staff who manage accounts but not staff.
    actingAsStaff(Role::Moderator, [Permission::UsersManage]);
    $this->postJson("/api/v1/admin/users/{$moderator->id}/status", ['status' => 'banned', 'note' => 'Test'])->assertForbidden()
        ->assertJsonPath('code', 'user.staff_protected');
    $this->getJson('/api/v1/admin/users/'.$moderator->id)->assertJsonPath('data.can.ban', false);
    $this->postJson("/api/v1/admin/users/{$this->student->id}/sign-out")->assertOk();

    Sanctum::actingAs($this->admin); // admins manage staff too
    $this->postJson("/api/v1/admin/users/{$moderator->id}/status", ['status' => 'suspended', 'note' => 'Test'])->assertOk();

    Sanctum::actingAs(actingAsStaff(Role::Moderator)); // can view users, can't manage them
    $this->getJson('/api/v1/admin/users')->assertOk();
    $this->postJson("/api/v1/admin/users/{$this->student->id}/sign-out")->assertForbidden();
});
