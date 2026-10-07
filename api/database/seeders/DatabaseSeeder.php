<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Reference data — safe to run in every environment.
        $this->call([
            ProvinceSeeder::class,
            RolesAndPermissionsSeeder::class,
        ]);

        // Local/staging demo admin — only when a number you control is configured (never a guessed real number).
        $demoAdminPhone = config('yekkola.demo_admin_phone');
        if (app()->environment(['local', 'staging']) && is_string($demoAdminPhone) && $demoAdminPhone !== '') {
            User::query()->firstOrCreate(
                ['phone_e164' => PhoneNumber::fromString($demoAdminPhone)->e164],
                ['name' => 'Admin Démo'],
            )->assignRole(Role::SuperAdmin->value, Role::Student->value);
        }
    }
}
