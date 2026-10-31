<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Seeder;

/**
 * Test accounts for local and staging, one per role, to try the back office and to "View as" from a super admin:
 * a student, a moderator, an admin, finance staff (admin + finance permissions) and support staff (moderator + user
 * management). Professors with courses come from DemoCatalogueSeeder.
 *
 * Demo phone numbers (`yekkola.demo_phone_prefix` + 9xxx) are never texted. Staff also get an email
 * (`demo-<role>@yekkola.test`) and, when `DEMO_STAFF_PASSWORD` is set, that password (no forced change: test only).
 * Idempotent: existing accounts are updated, not duplicated.
 */
final class DemoUsersSeeder extends Seeder
{
    /** @var list<array{n: string, name: string, role: Role, email: string|null, extra: list<Permission>}> */
    private const ACCOUNTS = [
        ['n' => '9001', 'name' => 'Démo Étudiant', 'role' => Role::Student, 'email' => null, 'extra' => []],
        ['n' => '9002', 'name' => 'Démo Modérateur', 'role' => Role::Moderator, 'email' => 'demo-moderateur@yekkola.test', 'extra' => []],
        ['n' => '9003', 'name' => 'Démo Admin', 'role' => Role::Admin, 'email' => 'demo-admin@yekkola.test', 'extra' => []],
        ['n' => '9004', 'name' => 'Démo Finance', 'role' => Role::Admin, 'email' => 'demo-finance@yekkola.test', 'extra' => [
            Permission::FinanceView, Permission::FinanceRefunds, Permission::FinancePayouts, Permission::FinanceAdjustments,
        ]],
        ['n' => '9005', 'name' => 'Démo Support', 'role' => Role::Moderator, 'email' => 'demo-support@yekkola.test', 'extra' => [
            Permission::UsersManage, Permission::CommerceView,
        ]],
    ];

    public function run(Hasher $hasher): void
    {
        if (! app()->environment(['local', 'staging', 'testing'])) {
            return; // never in production
        }
        $password = config('yekkola.demo_staff_password');
        $prefix = (string) config('yekkola.demo_phone_prefix');

        foreach (self::ACCOUNTS as $account) {
            $user = User::query()->firstOrNew(['phone_e164' => $prefix.$account['n']]);
            $user->forceFill([
                'name' => $account['name'],
                'email' => $account['email'],
                'phone_verified_at' => $user->phone_verified_at ?? now(),
            ]);
            if ($account['email'] !== null && is_string($password) && $password !== '') {
                $user->forceFill(['password' => $hasher->make($password), 'must_change_password' => false, 'password_changed_at' => now()]);
            }
            $user->save();

            $roles = array_values(array_unique([Role::Student->value, $account['role']->value]));
            $user->syncRoles($roles);
            $user->syncPermissions(array_map(fn (Permission $p) => $p->value, $account['extra']));
        }

        $this->command?->info('Demo users ready: student, moderator, admin, finance, support ('.$prefix.'9001–9005).');
    }
}
